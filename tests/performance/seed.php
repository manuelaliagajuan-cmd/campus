<?php

use App\Models\Course;
use App\Models\OnlineSession;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Controlled performance fixtures only. Never use production data or database names.
if (getenv('APP_ENV') !== 'testing' || getenv('CEC_PERF_ALLOW_SEED') !== '1' || ! getenv('CEC_PERF_FIXTURE')) {
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.default');
$database = config('database.connections.'.$connection.'.database');
if (($connection === 'mysql' && $database !== 'cec_performance') || ($connection === 'sqlite' && basename($database) !== 'cec-capacity.sqlite')) {
    exit(1);
}
$projection = config('erp.projection_path');
if (! $projection || ! str_contains($projection, 'cec-capacity')) {
    exit(1);
}
$dir = dirname($projection);
if (! is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$admin = User::factory()->create(['role' => 'admin', 'email' => 'capacity-admin@example.test']);
$source = ['schema' => 1, 'revision' => 1, 'updated_at' => now()->toIso8601String(), 'courses' => [], 'students' => [], 'passes' => []];
$clients = [];
$cookie = config('session.cookie');
$key = app('encrypter')->getKey();
$guardName = auth()->guard()->getName();
for ($i = 1; $i <= 20; $i++) {
    $teacher = User::factory()->create(['role' => 'teacher', 'email' => 'capacity-teacher-'.$i.'@example.test']);
    $student = User::factory()->create(['email' => 'capacity-student-'.$i.'@example.test', 'erp_student_id' => 'A-'.$i]);
    $course = Course::create(['teacher_id' => $teacher->id, 'title' => 'Capacity classroom '.$i, 'published' => true, 'online_enabled' => true]);
    $course->students()->attach($student->id, ['source_erp' => true, 'source_manual' => false]);
    $room = OnlineSession::create(['scope' => 'course:'.$course->id, 'course_id' => $course->id, 'host_id' => $teacher->id, 'title' => 'Capacity classroom '.$i]);
    $source['courses'][] = ['id' => 'ERP-'.$i, 'name' => 'Capacity classroom '.$i, 'active' => true, 'campus_course_id' => $course->id];
    $source['students'][] = ['id' => 'A-'.$i, 'email' => $student->email, 'name' => 'Synthetic pupil '.$i, 'active' => true, 'courses' => ['ERP-'.$i]];
    $source['passes'][] = ['id' => 'BON-'.$i, 'student_id' => 'A-'.$i, 'name' => 'Synthetic package', 'teacher_id' => $teacher->id, 'sessions' => 5, 'used' => 0, 'remaining' => 5, 'start' => '', 'expires' => '', 'online_enabled' => false];
    foreach ([$teacher, $student] as $user) {
        $participant = (string) Str::uuid();
        $id = bin2hex(random_bytes(20));
        $payload = serialize(['_token' => bin2hex(random_bytes(20)), $guardName => $user->id]);
        if (config('session.encrypt')) {
            $payload = Crypt::encrypt($payload);
        }
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'CEC controlled capacity test', 'payload' => base64_encode($payload), 'last_activity' => time()]);
        $encrypted = app('encrypter')->encrypt(CookieValuePrefix::create($cookie, $key).$id, false);
        DB::table('online_participants')->insert(['id' => $participant, 'online_session_id' => $room->id, 'user_id' => $user->id, 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $clients[] = ['cookie' => $cookie.'='.rawurlencode($encrypted), 'url' => '/clases-online/'.$room->id.'/estado?participant='.$participant.'&after=0'];
    }
}
for ($i = 21; $i <= 500; $i++) {
    $source['students'][] = ['id' => 'A-'.$i, 'email' => 'capacity-unregistered-'.$i.'@example.test', 'active' => true, 'courses' => []];
}
file_put_contents($projection, "<?php exit; ?>\n".json_encode($source));
chmod($projection, 0600);
DB::table('erp_sync_states')->insert(['source' => 'gestion', 'revision' => 1, 'synced_at' => now()]);
file_put_contents(getenv('CEC_PERF_FIXTURE'), json_encode(['clients' => $clients, 'rooms' => 20, 'source_students' => 500]));
chmod(getenv('CEC_PERF_FIXTURE'), 0600);
echo "CEC_CAPACITY_FIXTURE=40_synthetic_accounts_20_rooms\n";
