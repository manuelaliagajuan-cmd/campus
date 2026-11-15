<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private User $student;

    private array $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cec-shop-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        $this->student = User::factory()->create(['email' => 'buyer@example.test']);
        User::factory()->create(['role' => 'admin']);
        config(['erp.projection_path' => $this->dir.'/campus.php', 'payments.erp_core' => base_path('tests/Fixtures/erp-purchase.php'), 'payments.stripe_key' => 'sk_live_test_double', 'payments.stripe_webhook' => 'whsec_test_double']);
        $this->source = ['schema' => 1, 'revision' => 1, 'updated_at' => now()->toIso8601String(), 'courses' => [], 'students' => [['id' => 'A-1', 'name' => 'Buyer', 'email' => $this->student->email, 'active' => true, 'courses' => []]], 'passes' => [], 'pass_types' => [['id' => 'TIP-1', 'name' => 'Bono online', 'price' => 12500, 'sessions' => 8, 'days' => 90, 'online_enabled' => true]]];
        $this->write();
        Http::preventStrayRequests();
    }

    private function write(): void
    {
        file_put_contents($this->dir.'/campus.php', "<?php exit; ?>\n".json_encode($this->source));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function checkout(string $key = 'pass:TIP-1'): StoreOrder
    {
        $token = (string) Str::uuid();
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_'.$token, 'url' => 'https://checkout.stripe.com/c/pay/'.$token])]);
        $this->actingAs($this->student)->post(route('store.checkout'), ['product' => $key, 'token' => $token, 'amount' => 1])->assertRedirect();

        return StoreOrder::findOrFail($token);
    }

    private function paid(StoreOrder $o): array
    {
        return ['id' => $o->stripe_session_id, 'client_reference_id' => $o->id, 'metadata' => ['campus_order' => $o->id], 'livemode' => true, 'payment_status' => 'paid', 'currency' => 'eur', 'amount_total' => $o->amount, 'customer_details' => ['name' => 'Parent buyer', 'email' => 'parent@example.test'], 'payment_intent' => ['id' => 'pi_'.$o->id, 'status' => 'succeeded', 'currency' => 'eur', 'livemode' => true, 'amount_received' => $o->amount, 'latest_charge' => ['payment_method_details' => ['type' => 'card', 'card' => ['wallet' => ['type' => 'apple_pay']]]]]];
    }

    private function webhook(array $session, ?string $key = null)
    {
        $body = json_encode(['id' => 'evt_test', 'type' => 'checkout.session.completed', 'livemode' => true, 'data' => ['object' => $session]]);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$body, $key ?? config('payments.stripe_webhook'));

        return $this->call('POST', route('store.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $body);
    }

    public function test_catalog_prices_are_current_and_checkout_ignores_client_amount(): void
    {
        $this->actingAs($this->student)->get(route('store.index'))->assertOk()->assertSee('125,00');
        $this->source['pass_types'][0]['price'] = 13000;
        $this->source['revision']++;
        $this->write();
        $o = $this->checkout();
        $this->assertSame(13000, $o->amount);
        Http::assertSent(fn ($request) => $request['line_items'][0]['price_data']['unit_amount'] === 13000);
        $this->assertFileDoesNotExist($this->dir.'/purchase-contract.json');
        $this->get(route('store.order', $o).'?payment_return=0')->assertSee('Pendiente de pago');
        $this->actingAs(User::factory()->create())->get(route('store.order', $o))->assertNotFound();
    }

    public function test_only_verified_success_records_exactly_one_purchase_and_keeps_buyer_and_payer(): void
    {
        $o = $this->checkout();
        $session = $this->paid($o);
        $this->webhook($session, 'wrong_secret')->assertBadRequest();
        $bad = $session;
        $bad['amount_total'] = 1;
        $this->webhook($bad)->assertUnprocessable();
        $this->assertNull($o->fresh()->paid_at);
        $this->webhook($session)->assertOk();
        $this->webhook($session)->assertOk();
        $o->refresh();
        $this->assertSame('fulfilled', $o->status);
        $this->assertSame('Apple Pay', $o->method);
        $this->assertSame('Parent buyer', $o->payer_name);
        $this->assertSame($this->student->name, $o->buyer_name);
        $this->assertCount(1, json_decode(file_get_contents($this->dir.'/purchase-contract.json'), true));
        $this->get(route('store.order', $o))->assertSee('Compra confirmada');
    }

    public function test_unpaid_and_test_events_never_grant_access(): void
    {
        $o = $this->checkout();
        $unpaid = $this->paid($o);
        $unpaid['payment_status'] = 'unpaid';
        $this->webhook($unpaid)->assertOk();
        $this->assertNull($o->fresh()->fulfilled_at);
        $test = $this->paid($o);
        $test['livemode'] = false;
        $this->webhook($test)->assertUnprocessable();
        $this->assertFileDoesNotExist($this->dir.'/purchase-contract.json');
    }

    public function test_paid_course_maps_to_original_course_without_monthly_erp_course_or_duplicate(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $c = Course::create(['teacher_id' => $teacher->id, 'title' => 'Digital English', 'published' => true, 'store_listed' => true, 'store_price_cents' => 2500]);
        $o = $this->checkout('course:'.$c->id);
        $this->webhook($this->paid($o))->assertOk();
        $this->assertDatabaseCount('courses', 1);
        $this->assertNull($c->fresh()->erp_id);
        $this->assertDatabaseHas('enrollments', ['user_id' => $this->student->id, 'course_id' => $c->id, 'source_erp' => true]);
        $this->actingAs($this->student)->get('/cursos/'.$c->id)->assertOk();
    }

    public function test_provider_unavailable_keeps_paid_order_for_retry_without_losing_payment(): void
    {
        $o = $this->checkout();
        config(['payments.erp_core' => null]);
        $this->withoutExceptionHandling();
        try {
            $this->webhook($this->paid($o));
            $this->fail('Expected fulfilment failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('paid', $o->fresh()->status);
            $this->assertNotNull($o->fresh()->paid_at);
        }$this->assertNull($o->fresh()->fulfilled_at);
        config(['payments.erp_core' => base_path('tests/Fixtures/erp-purchase.php')]);
        $this->webhook($this->paid($o))->assertOk();
        $this->assertSame('fulfilled', $o->fresh()->status);
    }

    public function test_signed_sandbox_event_is_acknowledged_without_real_financial_records(): void
    {
        $o = $this->checkout();
        $body = json_encode(['type' => 'checkout.session.completed', 'livemode' => false, 'data' => ['object' => $this->paid($o)]]);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$body, config('payments.stripe_webhook'));
        $this->call('POST', route('store.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $body)->assertOk()->assertJsonPath('ignored', true);
        $this->assertNull($o->fresh()->paid_at);
        $this->assertFileDoesNotExist($this->dir.'/purchase-contract.json');
    }

    public function test_course_price_editor_stores_exact_cents_and_rejects_invalid_minimum(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($teacher)->post('/docente/cursos', ['title' => 'Course price', 'language' => 'English', 'level' => 'B1', 'published' => 1, 'store_listed' => 1, 'store_price' => '12.34'])->assertRedirect();
        $this->assertDatabaseHas('courses', ['title' => 'Course price', 'store_price_cents' => 1234, 'store_listed' => true]);
        $this->postJson('/docente/cursos', ['title' => 'Bad price', 'language' => 'English', 'level' => 'B1', 'published' => 1, 'store_listed' => 1, 'store_price' => '0.49'])->assertUnprocessable();
    }
}
