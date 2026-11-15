<?php

namespace App\Services;

use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class StripeCheckout
{
    private static function http()
    {
        return Http::withToken(config('payments.stripe_key'))->acceptJson()->asForm()->connectTimeout(10)->timeout(25);
    }

    public static function create(StoreOrder $order): string
    {
        if ($order->checkout_url) {
            return $order->checkout_url;
        }$response = self::http()->withHeaders(['Idempotency-Key' => 'campus-order-'.$order->id])->post('https://api.stripe.com/v1/checkout/sessions', ['mode' => 'payment', 'client_reference_id' => $order->id, 'customer_email' => $order->buyer_email, 'metadata' => ['campus_order' => $order->id], 'line_items' => [['price_data' => ['currency' => 'eur', 'unit_amount' => $order->amount, 'product_data' => ['name' => $order->product_name]], 'quantity' => 1]], 'success_url' => route('store.order', $order).'?payment_return=1', 'cancel_url' => route('store.order', $order), 'expires_at' => now()->addMinutes(30)->timestamp]);
        if (! $response->successful() || ! is_string($response->json('id')) || ! str_starts_with($response->json('url') ?? '', 'https://checkout.stripe.com/')) {
            throw new \RuntimeException('No se pudo abrir el pago.');
        }$order->update(['stripe_session_id' => $response->json('id'), 'checkout_url' => $response->json('url')]);

        return $order->checkout_url;
    }

    public static function verifyWebhook(Request $r): array
    {
        $raw = $r->getContent();
        abort_if(strlen($raw) > 1048576, 413);
        $parts = [];
        foreach (explode(',', $r->header('Stripe-Signature', '')) as $part) {
            $v = explode('=', $part, 2);
            if (count($v) === 2) {
                $parts[$v[0]][] = $v[1];
            }
        }$timestamp = $parts['t'][0] ?? '';
        $secret = config('payments.stripe_webhook');
        abort_unless($secret && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 400);
        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        $valid = false;
        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                $valid = true;
            }
        }abort_unless($valid, 400);
        $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

        return $event;
    }

    public static function refresh(StoreOrder $order): void
    {
        if (! $order->stripe_session_id || in_array($order->status, ['paid', 'fulfilled'], true)) {
            return;
        }$response = self::http()->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($order->stripe_session_id), ['expand' => ['payment_intent.latest_charge']]);
        if (! $response->successful()) {
            throw new \RuntimeException('No se pudo verificar el pago.');
        }self::confirm($order, $response->json());
    }

    public static function confirm(StoreOrder $order, array $session): void
    {
        if (($session['payment_status'] ?? '') !== 'paid') {
            return;
        }abort_unless(($session['id'] ?? null) === $order->stripe_session_id && ($session['client_reference_id'] ?? null) === $order->id && ($session['metadata']['campus_order'] ?? null) === $order->id && ($session['livemode'] ?? false) === true && ($session['amount_total'] ?? null) === $order->amount && ($session['currency'] ?? null) === 'eur', 422);
        $intent = $session['payment_intent'] ?? null;
        if (is_string($intent)) {
            $response = self::http()->get('https://api.stripe.com/v1/payment_intents/'.rawurlencode($intent), ['expand' => ['latest_charge']]);
            if (! $response->successful()) {
                throw new \RuntimeException('No se pudo verificar el medio de pago.');
            }$intent = $response->json();
        }abort_unless(is_array($intent) && ($intent['status'] ?? '') === 'succeeded' && ($intent['amount_received'] ?? null) === $order->amount && ($intent['currency'] ?? null) === 'eur' && ($intent['livemode'] ?? false) === true, 422);
        $details = $intent['latest_charge']['payment_method_details'] ?? [];
        $kind = $details['type'] ?? '';
        $wallet = $details['card']['wallet']['type'] ?? '';
        $method = ['apple_pay' => 'Apple Pay', 'google_pay' => 'Google Pay'][$wallet] ?? (['card' => 'Tarjeta', 'paypal' => 'PayPal', 'bizum' => 'Bizum'][$kind] ?? 'Stripe');
        DB::transaction(function () use ($order, $session, $intent, $method) {
            $o = StoreOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($o->status, ['paid', 'fulfilled'], true)) {
                abort_unless($o->payment_id === $intent['id'], 422);

                return;
            }$o->update(['status' => 'paid', 'payment_id' => $intent['id'], 'method' => $method, 'payer_name' => mb_substr((string) ($session['customer_details']['name'] ?? ''), 0, 190), 'payer_email' => mb_substr((string) ($session['customer_details']['email'] ?? ''), 0, 190), 'paid_at' => is_int($intent['latest_charge']['created'] ?? null) ? Carbon::createFromTimestampUTC($intent['latest_charge']['created']) : now()]);
        });
        OnlinePurchaseFulfillment::fulfill($order->fresh());
    }
}
