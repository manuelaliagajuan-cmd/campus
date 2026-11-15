<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClassroomConnectivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClassroomConnectivityTest extends TestCase
{
    use RefreshDatabase;

    private function servers(): array
    {
        return [['urls' => ['turns:turn.cloudflare.com:443?transport=tcp'], 'username' => 'temporary-user', 'credential' => 'temporary-password']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_only_admin_can_configure_and_master_keys_are_encrypted_and_never_exposed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['turn_key' => str_repeat('a', 32), 'turn_token' => str_repeat('secret-', 8)];
        foreach (['teacher', 'student'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('connectivity.edit'))->assertForbidden();
            $this->post(route('connectivity.update'), $data)->assertForbidden();
            $this->getJson(route('connectivity.test'))->assertForbidden();
        }
        Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['iceServers' => $this->servers()], 201)]);
        $this->actingAs($admin)->post(route('connectivity.update'), $data)->assertRedirect();
        $encrypted = Storage::disk('local')->get('rtc/cloudflare.enc');
        $this->assertStringNotContainsString($data['turn_token'], $encrypted);
        $this->get(route('connectivity.edit'))->assertOk()->assertSee('Servicio configurado')->assertDontSee($data['turn_token'])->assertDontSee($data['turn_key']);
        $this->assertSame($this->servers(), ClassroomConnectivity::servers('user-1:room-1'));
        $this->assertSame($this->servers(), ClassroomConnectivity::servers('user-1:room-1'));
        Http::assertSentCount(2);
        ClassroomConnectivity::servers('user-2:room-1');
        Http::assertSentCount(3);
        $this->getJson(route('connectivity.test'))->assertOk()->assertJsonPath('relayAvailable', true)->assertDontSee($data['turn_token']);
    }

    public function test_provider_failure_does_not_replace_working_settings_or_leak_tokens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['turn_key' => str_repeat('b', 32), 'turn_token' => str_repeat('confidential-', 4)];
        Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['message' => $data['turn_token']], 401)]);
        $this->actingAs($admin)->post(route('connectivity.update'), $data)->assertSessionHasErrors('turn_key')->assertSessionMissing('_old_input.turn_token');
        Storage::disk('local')->assertMissing('rtc/cloudflare.enc');
        $this->post(route('connectivity.update'), ['turn_key' => '../other', 'turn_token' => $data['turn_token']])->assertSessionHasErrors('turn_key')->assertSessionMissing('_old_input.turn_token');
    }

    public function test_temporary_provider_outage_reuses_only_unexpired_relay_credentials(): void
    {
        Http::fake(['rtc.live.cloudflare.com/*' => Http::sequence()->push(['iceServers' => $this->servers()], 201)->push(['iceServers' => $this->servers()], 201)->push([], 503)->push([], 503)]);
        ClassroomConnectivity::configure(str_repeat('c', 32), str_repeat('test-secret-', 4));
        $servers = ClassroomConnectivity::servers('outage-test');
        $this->travel(11)->minutes();
        $this->assertSame($servers, ClassroomConnectivity::servers('outage-test'));
        $this->travel(25)->hours();
        $this->assertFalse(ClassroomConnectivity::hasRelay(ClassroomConnectivity::servers('outage-test')));
    }
}
