<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Course;
use App\Models\OnlineSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnlineLearningTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'student'): User
    {
        return User::factory()->create(['name' => ucfirst($role), 'email' => uniqid().'@example.test', 'password' => 'DemoCampus12345', 'role' => $role, 'email_verified_at' => now()]);
    }

    private function setupRoom(): array
    {
        $teacher = $this->user('teacher');
        $student = $this->user();
        $course = Course::create(['title' => 'Online English', 'teacher_id' => $teacher->id, 'published' => true, 'online_enabled' => true, 'language' => 'English', 'level' => 'B1']);
        $course->students()->attach($student, ['source_manual' => true]);
        $this->actingAs($teacher)->post(route('online.start'), ['scope' => 'course:'.$course->id])->assertRedirect();

        return [$teacher, $student, $course, OnlineSession::firstOrFail()];
    }

    public function test_only_enabled_enrolled_rooms_are_visible_and_only_teacher_can_start(): void
    {
        [$teacher,$student,$course,$session] = $this->setupRoom();
        $outsider = $this->user();
        $this->actingAs($outsider)->get(route('online.show', $session))->assertForbidden();
        $this->post(route('online.start'), ['scope' => $session->scope])->assertForbidden();
        $this->actingAs($student)->get(route('online.index'))->assertOk()->assertSee('Online English');
        $this->post(route('online.start'), ['scope' => $session->scope])->assertForbidden();
        $course->update(['online_enabled' => false]);
        $this->get(route('online.show', $session))->assertForbidden();
        $this->get(route('online.index'))->assertDontSee('Online English');
    }

    public function test_signals_are_private_chat_and_board_are_scoped_and_revocation_blocks_polling(): void
    {
        [$teacher,$student,$course,$s] = $this->setupRoom();
        $t = $this->actingAs($teacher)->postJson(route('online.join', $s))->assertOk()->json('participant');
        $a = $this->actingAs($student)->postJson(route('online.join', $s))->assertOk()->json('participant');
        $b = $this->actingAs($student)->postJson(route('online.join', $s))->json('participant');
        $this->actingAs($teacher)->postJson(route('online.event', $s), ['participant' => $t, 'recipient' => $a, 'kind' => 'signal', 'payload' => ['type' => 'offer', 'sdp' => 'private-sdp']])->assertOk();
        $this->postJson(route('online.event', $s), ['participant' => $t, 'kind' => 'chat', 'payload' => ['text' => 'Hello classroom']])->assertOk();
        $this->actingAs($student)->getJson(route('online.poll', $s).'?participant='.$a)->assertOk()->assertSee('private-sdp')->assertSee('Hello classroom');
        $this->getJson(route('online.poll', $s).'?participant='.$b)->assertOk()->assertDontSee('private-sdp')->assertSee('Hello classroom');
        $this->postJson(route('online.event', $s), ['participant' => $t, 'kind' => 'chat', 'payload' => ['text' => 'Impersonation']])->assertForbidden();
        $this->postJson(route('online.event', $s), ['participant' => $a, 'kind' => 'clear', 'payload' => ['clear' => true]])->assertForbidden();
        $this->postJson(route('online.event', $s), ['participant' => $a, 'kind' => 'stroke', 'payload' => ['color' => '#212a5e', 'width' => 2, 'points' => [[0.1, 0.2], [0.3, 0.4]]]])->assertOk();
        $this->postJson(route('online.event', $s), ['participant' => $a, 'kind' => 'stroke', 'payload' => ['color' => 'bad', 'width' => 500, 'points' => [[5, 5], [3, 3]]]])->assertUnprocessable();
        $course->students()->detach($student);
        $this->getJson(route('online.poll', $s).'?participant='.$a)->assertForbidden();
    }

    public function test_end_stops_events_and_rating_requires_joined_student_and_valid_stars(): void
    {
        [$teacher,$student,$course,$s] = $this->setupRoom();
        $p = $this->actingAs($student)->postJson(route('online.join', $s))->json('participant');
        $this->post(route('online.rate', $s), ['stars' => 5])->assertForbidden();
        $this->post(route('online.end', $s))->assertForbidden();
        $this->actingAs($teacher)->post(route('online.end', $s))->assertRedirect();
        $this->actingAs($student)->getJson(route('online.poll', $s).'?participant='.$p)->assertJson(['ended' => true]);
        $this->postJson(route('online.event', $s), ['participant' => $p, 'kind' => 'chat', 'payload' => ['text' => 'after']])->assertStatus(409);
        $this->postJson(route('online.rate', $s), ['stars' => 6])->assertUnprocessable();
        $this->post(route('online.rate', $s), ['stars' => 5, 'feedback' => 'Muy buena clase'])->assertRedirect();
        $this->post(route('online.rate', $s), ['stars' => 4, 'feedback' => 'Updated'])->assertRedirect();
        $this->assertDatabaseCount('online_ratings', 1);
        $this->actingAs($teacher)->get(route('online.show', $s))->assertSee('Updated');
        $neverJoined = $this->user();
        $course->students()->attach($neverJoined, ['source_manual' => true]);
        $this->actingAs($neverJoined)->post(route('online.rate', $s), ['stars' => 1])->assertForbidden();
    }

    public function test_room_capacity_expiration_and_repeat_start_are_enforced(): void
    {
        [$teacher,$student,$course,$s] = $this->setupRoom();
        $this->actingAs($teacher)->post(route('online.start'), ['scope' => $s->scope])->assertRedirect(route('online.show', $s));
        $this->assertDatabaseCount('online_sessions', 1);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('online.join', $s))->assertOk();
        }$this->postJson(route('online.join', $s))->assertStatus(409);
        DB::table('online_participants')->update(['seen_at' => now()->subMinute()]);
        $this->postJson(route('online.join', $s))->assertOk();
        $s->update(['created_at' => now()->subHours(7)]);
        $this->get(route('online.show', $s))->assertSee('La sesión ha finalizado.');
        $this->post(route('online.start'), ['scope' => $s->scope])->assertRedirect();
        $this->assertDatabaseCount('online_sessions', 2);
    }

    public function test_audio_upload_is_private_course_scoped_and_attempt_keeps_original_audio(): void
    {
        Storage::fake('local');
        [$teacher,$student,$course] = $this->setupRoom();
        $wav = 'RIFF'.pack('V', 16036).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', 16000).str_repeat("\0", 16000);
        $upload = UploadedFile::fake()->createWithContent('listening.wav', $wav);
        $audio = $this->actingAs($teacher)->postJson(route('staff.audio', $course), ['file' => $upload])->assertOk()->json();
        $id = $audio['id'];
        $this->actingAs($student)->get($audio['url'])->assertOk();
        $this->postJson(route('staff.audio', $course), ['file' => $upload])->assertForbidden();
        $outsider = $this->user();
        $this->actingAs($outsider)->get($audio['url'])->assertForbidden();
        $questions = json_encode([['type' => 'multiple', 'prompt' => 'What did you hear?', 'options' => ['Hello', 'Bye'], 'correct' => 0, 'feedback' => 'Listen again']]);
        $this->actingAs($teacher)->post(route('activities.store'), ['title' => 'Listening', 'course_id' => $course->id, 'mode' => 'practice', 'max_attempts' => 0, 'pass_percent' => 70, 'questions_json' => $questions, 'audio_resource_id' => $id, 'published' => 1])->assertRedirect();
        $activity = Activity::firstOrFail();
        $this->actingAs($student)->post(route('activities.start', $activity))->assertRedirect();
        $attempt = $activity->attempts()->firstOrFail();
        $activity->update(['audio_resource_id' => null]);
        $this->get(route('attempts.show', $attempt))->assertOk()->assertSee('Escucha el audio')->assertSee('listening.wav');
        $this->assertEquals($id, $attempt->audio_resource_id);
        $other = Course::create(['title' => 'Other', 'teacher_id' => $teacher->id, 'published' => true]);
        $this->actingAs($teacher)->postJson(route('staff.lesson.store', $other), ['title' => 'Invalid audio', 'unit' => '1', 'position' => 1, 'audio_resource_id' => $id])->assertUnprocessable();
        $this->postJson(route('staff.audio', $course), ['file' => UploadedFile::fake()->createWithContent('bad.mp3', '<?php echo 1;')])->assertUnprocessable();
    }

    public function test_notes_are_shared_separate_from_chat_and_conflicts_keep_saved_version(): void
    {
        [$teacher,$student,$course,$session] = $this->setupRoom();
        $t = $this->actingAs($teacher)->postJson(route('online.join', $session))->json('participant');
        $s = $this->actingAs($student)->postJson(route('online.join', $session))->json('participant');
        $this->postJson(route('online.notes', $session), ['participant' => $s, 'text' => 'Vocabulary: friendship', 'revision' => 0])->assertOk()->assertJsonPath('notes.revision', 1);
        $this->actingAs($teacher)->getJson(route('online.poll', $session).'?participant='.$t)->assertJsonPath('notes.text', 'Vocabulary: friendship');
        $this->postJson(route('online.notes', $session), ['participant' => $t, 'text' => 'Stale overwrite', 'revision' => 0])->assertStatus(409)->assertJsonPath('notes.text', 'Vocabulary: friendship');
        $this->postJson(route('online.notes', $session), ['participant' => $t, 'text' => 'Vocabulary: friendship and trust', 'revision' => 1])->assertOk()->assertJsonPath('notes.revision', 2);
        $this->postJson(route('online.event', $session), ['participant' => $t, 'kind' => 'sharing', 'payload' => ['active' => true]])->assertOk();
        $this->actingAs($student)->getJson(route('online.poll', $session).'?participant='.$s)->assertSee('sharing');
        $this->postJson(route('online.notes', $session), ['participant' => $t, 'text' => 'Impersonation', 'revision' => 2])->assertForbidden();
        $this->actingAs($teacher)->post(route('online.end', $session))->assertRedirect();
        $this->get(route('online.show', $session))->assertSee('Vocabulary: friendship and trust');
        $this->postJson(route('online.notes', $session), ['participant' => $t, 'text' => 'After end', 'revision' => 2])->assertStatus(409);
    }

    public function test_connection_retries_reuse_participant_and_recovery_requires_same_room_membership(): void
    {
        [$teacher, $student, $course, $room] = $this->setupRoom();
        $connection = (string) Str::uuid();
        $url = route('online.join', $room);
        $this->actingAs($student)->postJson($url, ['connection' => $connection])->assertOk()->assertJsonPath('participant', $connection);
        $this->postJson($url, ['connection' => $connection])->assertOk()->assertJsonPath('participant', $connection);
        $this->assertSame(1, DB::table('online_participants')->where('online_session_id', $room->id)->count());
        $this->actingAs($teacher)->postJson($url, ['connection' => $connection])->assertForbidden();
        $teacherConnection = $this->postJson($url)->json('participant');
        $this->postJson(route('online.event', $room), ['participant' => $teacherConnection, 'recipient' => $connection, 'kind' => 'signal', 'payload' => ['type' => 'restart']])->assertOk();
        $this->actingAs($student)->getJson(route('online.connectivity', $room).'?participant='.$connection)->assertOk()->assertJsonStructure(['iceServers', 'relayAvailable']);
        $course->students()->detach($student);
        $this->getJson(route('online.connectivity', $room).'?participant='.$connection)->assertForbidden();
    }
}
