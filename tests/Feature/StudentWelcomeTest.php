<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentWelcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_student_sees_reassuring_review_message_without_internal_program_or_dead_button(): void
    {
        $user = User::factory()->create(['role' => 'student', 'email_verified_at' => null]);
        foreach (['/campus', '/mis-accesos'] as $url) {
            $response = $this->actingAs($user)->get($url)->assertOk()->assertSee('Estamos preparando tu acceso')->assertSee('No necesitas hacer nada más.')->assertDontSee('Cómo confirmar mi correo')->assertDontSee('Nuevos accesos')->assertDontSee('Gestión')->assertDontSee('Contacta con el centro');
        }
    }

    public function test_english_student_view_has_the_same_clear_message_without_an_email_action(): void
    {
        $user = User::factory()->create(['role' => 'student', 'email_verified_at' => null, 'locale' => 'en']);
        $response = $this->actingAs($user)->get('/mis-accesos')->assertOk()->assertSee('We are preparing your access')->assertSee('You do not need to do anything else.');
        $body = preg_replace('~<script\b[^>]*>.*?</script>~s', '', $response->getContent());
        $this->assertStringNotContainsString('Gestión', $body);
        $this->assertStringNotContainsString('/cuenta/confirmar-correo', $body);
    }

    public function test_old_confirmation_post_provides_a_review_status_without_claiming_to_send_mail(): void
    {
        $user = User::factory()->create(['role' => 'student', 'email_verified_at' => null]);
        $this->actingAs($user)->from('/mis-accesos')->post('/cuenta/confirmar-correo')->assertRedirect('/mis-accesos')->assertSessionHas('status', 'Estamos revisando tu inscripción. En cuanto el centro confirme tu acceso, aquí aparecerán tus cursos y bonos. No necesitas hacer nada más.');
    }
}
