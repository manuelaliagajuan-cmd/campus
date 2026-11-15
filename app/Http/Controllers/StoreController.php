<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\StoreOrder;
use App\Services\ErpConnection;
use App\Services\OnlinePurchaseFulfillment;
use App\Services\StoreCatalog;
use App\Services\StoreOrigin;
use App\Services\StripeCheckout;
use App\Services\TeacherAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    public function index(Request $r)
    {
        try {
            $items = StoreCatalog::all();
            $unavailable = false;
        } catch (\Throwable $e) {
            $items = [];
            $unavailable = true;
        }

        $d = $r->validate(['source_token' => 'nullable|string|max:2048', 'origin' => 'nullable|string|max:30', 'producto' => 'nullable|string|max:120']);
        $origin = ! empty($d['source_token']) ? StoreOrigin::host($d['source_token']) : (($r->query('origin') === 'web' && $r->hasValidSignature()) ? 'cecalicante.es' : 'campus.cecalicante.es');
        $sourceToken = StoreOrigin::token($origin);
        $selectedProduct = $r->query('producto', '');

        return view('store.index', ['sourceToken' => $sourceToken, 'selectedProduct' => $selectedProduct, 'salesOrigin' => $origin, 'items' => $items, 'enabled' => StoreCatalog::enabled(), 'unavailable' => $unavailable, 'orders' => StoreOrder::when($r->user()->role !== 'admin', fn ($q) => $q->where('user_id', $r->user()->id))->latest()->limit(20)->get()]);
    }

    public function state(Request $r)
    {
        try {
            return response()->json(['version' => hash('sha256', json_encode(StoreCatalog::all())), 'enabled' => StoreCatalog::enabled()]);
        } catch (\Throwable $e) {
            return response()->json(['unavailable' => true], 503);
        }
    }

    public function pass(Request $r, string $type)
    {
        $r->validate(['source_token' => 'nullable|string|max:2048']);
        $items = StoreCatalog::all();
        $item = $items['pass:'.$type] ?? null;
        abort_unless($item, 404);

        return view('store.pass', ['sourceToken' => $r->query('source_token'), 'salesOrigin' => StoreOrigin::host($r->query('source_token')), 'item' => $item, 'catalogVersion' => hash('sha256', json_encode($items)), 'enabled' => StoreCatalog::enabled()]);
    }

    public function availability(Request $r, string $type)
    {
        $d = $r->validate(['month' => 'required|date_format:Y-m', 'subject' => 'nullable|string|max:100', 'teacher_id' => 'nullable|integer|min:1']);
        $item = StoreCatalog::all()['pass:'.$type] ?? null;
        abort_unless($item, 404);

        return response()->json(TeacherAvailability::month($item, $d['month'], $d['subject'] ?? null, isset($d['teacher_id']) ? (int) $d['teacher_id'] : null));
    }

    public function checkout(Request $r)
    {
        abort_unless(StoreCatalog::enabled(), 503);
        $d = $r->validate(['product' => 'required|string|max:120', 'token' => 'required|uuid', 'source_token' => 'nullable|string|max:2048']);
        if ($r->user()->role !== 'student' || ErpConnection::sync($r->user())['state'] !== 'connected') {
            return redirect()->route('store.index')->with('status', __('Estamos preparando tu cuenta. El centro debe confirmar tu acceso antes de comprar.'));
        }
        $origin = StoreOrigin::host($d['source_token'] ?? null);
        $item = StoreCatalog::all()[$d['product']] ?? null;
        abort_unless($item, 404);
        if ($item['kind'] === 'course') {
            abort_if(Course::findOrFail($item['course_id'])->students()->whereKey($r->user()->id)->exists(), 422, __('Ya tienes acceso a este curso.'));
        }
        $order = DB::transaction(function () use ($r, $d, $item, $origin) {
            $prior = StoreOrder::find($d['token']);
            if ($prior) {
                abort_unless($prior->user_id === $r->user()->id && $prior->product_key === $item['key'], 403);

                return $prior;
            }

            return StoreOrder::create(['id' => $d['token'], 'user_id' => $r->user()->id, 'erp_student_id' => $r->user()->fresh()->erp_student_id, 'kind' => $item['kind'], 'product_key' => $item['key'], 'product_name' => $item['name'], 'product' => $item, 'amount' => $item['amount'], 'buyer_name' => $r->user()->name, 'buyer_email' => $r->user()->email, 'sales_origin' => $origin]);
        });
        if (in_array($order->status, ['paid', 'fulfilled'], true)) {
            return redirect()->route('store.order', $order);
        }try {
            return redirect()->away(StripeCheckout::create($order));
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('store.order', $order)->with('status', __('No se pudo abrir el pago. No se ha confirmado ningún cobro. Inténtalo de nuevo.'));
        }
    }

    public function order(Request $r, StoreOrder $order)
    {
        abort_unless($order->user_id === $r->user()->id || $r->user()->role === 'admin', 404);
        try {
            if ($r->boolean('payment_return')) {
                StripeCheckout::refresh($order);
            }if ($order->fresh()->status === 'paid') {
                OnlinePurchaseFulfillment::fulfill($order->fresh());
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return view('store.order', ['order' => $order->fresh()]);
    }

    public function webhook(Request $r)
    {
        $event = StripeCheckout::verifyWebhook($r);
        if (($event['livemode'] ?? false) !== true) {
            return response()->json(['received' => true, 'ignored' => true]);
        }
        if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $session = $event['data']['object'] ?? [];
            $order = StoreOrder::where('stripe_session_id', $session['id'] ?? '')->first();
            if (! $order) {
                return response()->json(['received' => true]);
            }StripeCheckout::confirm($order, $session);
        }

        return response()->json(['received' => true]);
    }
}
