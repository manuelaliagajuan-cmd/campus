<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\OnlineSession;
use App\Models\User;
use App\Services\ErpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CampusPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private array $source;

    private User $student;

    private OnlineSession $room;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->dir = sys_get_temp_dir().'/cec-performance-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        config(['erp.projection_path' => $this->dir.'/campus.php']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->student = User::factory()->create();
        $course = Course::create(['teacher_id' => $admin->id, 'title' => 'Classroom', 'published' => true, 'online_enabled' => true]);
        for ($i = 0; $i < 29; $i++) {
            Course::create(['teacher_id' => $admin->id, 'title' => 'Other '.$i, 'published' => true, 'online_enabled' => true]);
        }
        $this->source = ['schema' => 1, 'revision' => 1, 'updated_at' => now()->toIso8601String(), 'courses' => [['id' => 'ERP-1', 'name' => 'Classroom', 'active' => true, 'campus_course_id' => $course->id]], 'students' => [['id' => 'A-1', 'email' => $this->student->email, 'active' => true, 'courses' => ['ERP-1']]], 'passes' => []];
        for ($i = 2; $i <= 500; $i++) {
            $this->source['students'][] = ['id' => 'A-'.$i, 'email' => 'pupil'.$i.'@example.test', 'active' => true, 'courses' => []];
        }
        $this->write();
        $this->room = OnlineSession::create(['scope' => 'course:'.$course->id, 'course_id' => $course->id, 'host_id' => $admin->id, 'title' => 'Classroom']);
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

    public function test_repeated_poll_does_not_rewrite_enrolments_or_publish_global_registry(): void
    {
        $p = $this->actingAs($this->student)->postJson(route('online.join', $this->room))->assertOk()->json('participant');
        $before = DB::table('enrollments')->where('user_id', $this->student->id)->first()->updated_at;
        $this->travel(2)->seconds();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson(route('online.poll', $this->room).'?participant='.$p)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        fwrite(STDERR, 'CEC_POLL_SQL_QUERIES='.count($queries).PHP_EOL);
        $this->assertLessThanOrEqual(28, count($queries), 'Polling should not scan every course or rebuild the global directory.');
        $this->assertSame($before, DB::table('enrollments')->where('user_id', $this->student->id)->first()->updated_at);
        $writes = array_filter($queries, fn ($q) => preg_match('/^(insert|update|delete).*?(enrollments|erp_sync_states)/i', $q['query']));
        $this->assertCount(0, $writes, 'Unchanged access must not take global or membership write locks.');
    }

    public function test_cached_projection_still_revokes_access_immediately_and_fails_closed_on_corruption(): void
    {
        $p = $this->actingAs($this->student)->postJson(route('online.join', $this->room))->assertOk()->json('participant');
        $this->getJson(route('online.poll', $this->room).'?participant='.$p)->assertOk();
        $this->source['students'][0]['active'] = false;
        $this->source['revision']++;
        $this->write();
        $this->getJson(route('online.poll', $this->room).'?participant='.$p)->assertForbidden();
        file_put_contents($this->dir.'/campus.php', 'invalid projection');
        $this->assertSame('unavailable', ErpConnection::sync($this->student)['state']);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $this->student->id, 'source_erp' => true]);
    }
}
