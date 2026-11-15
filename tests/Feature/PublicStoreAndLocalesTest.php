<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StoreOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PublicStoreAndLocalesTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cec-public-store-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        config(['erp.projection_path' => $this->dir.'/campus.php']);
        file_put_contents($this->dir.'/campus.php', "<?php exit; ?>\n".json_encode(['schema' => 1, 'revision' => 1, 'updated_at' => now()->toIso8601String(), 'courses' => [], 'students' => [['id' => 'PRIVATE-1', 'email' => 'private@example.test', 'name' => 'PRIVATE PUPIL', 'active' => true, 'courses' => []]], 'passes' => [], 'pass_types' => [['id' => 'T-1', 'name' => 'Bono público', 'price' => 12000, 'sessions' => 5, 'days' => 30, 'online_enabled' => true]]]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_public_catalog_is_live_minimal_and_cors_only_allows_the_website(): void
    {
        $this->withHeader('Origin', 'https://cecalicante.es')->getJson(route('store.public-catalog'))->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://cecalicante.es')->assertJsonPath('items.0.amount', 12000)->assertDontSee('private@example.test')->assertDontSee('PRIVATE PUPIL');
        $this->withHeader('Origin', 'https://other.example')->getJson(route('store.public-catalog'))->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_website_entry_preserves_signed_purchase_context_through_login(): void
    {
        $this->get(route('store.web-entry', ['producto' => 'pass:T-1']))->assertRedirect(route('login'));
        $intended = session('url.intended');
        $this->assertStringContainsString('signature=', $intended);
        $u = User::factory()->create(['role' => 'admin']);
        $this->actingAs($u)->get($intended)->assertOk()->assertSee('Compra iniciada en cecalicante.es');
        $this->get(route('store.index', ['origin' => 'web']))->assertOk()->assertDontSee('Compra iniciada en cecalicante.es');
    }

    public function test_origin_tokens_cannot_be_forged_and_expire(): void
    {
        $token = StoreOrigin::token('cecalicante.es');
        $this->assertSame('cecalicante.es', StoreOrigin::host($token));
        $this->travel(2)->days();
        try {
            StoreOrigin::host($token);
            $this->fail('Expired token accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_eight_native_interface_languages_keep_placeholders_and_original_course_titles(): void
    {
        $u = User::factory()->create(['role' => 'admin']);
        $this->actingAs($u);
        foreach (config('locales') as $code => $name) {
            $this->post(route('locale'), ['locale' => $code])->assertRedirect();
            $this->assertSame($code, $u->fresh()->locale);
            $this->assertNotSame('validation.required', __('validation.required'));
            $this->get('/campus')->assertOk()->assertSee('lang="'.$code.'"', false)->assertSee($name);
            if ($code !== 'es') {
                $catalog = json_decode(file_get_contents(lang_path($code.'.json')), true);
                foreach (json_decode(file_get_contents(lang_path('en.json')), true) as $key => $value) {
                    $this->assertArrayHasKey($key, $catalog);
                    preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $value, $a);
                    preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $catalog[$key], $b);
                    sort($a[0]);
                    sort($b[0]);
                    $this->assertSame($a[0], $b[0]);
                }
            }
        }
    }
}
