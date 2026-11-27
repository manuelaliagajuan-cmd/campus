<?php
declare(strict_types=1);

ini_set('display_errors','0');
umask(0077);
try {
    $home = rtrim((string)getenv('HOME'),'/');
    if ($home === '' || $home[0] !== '/') throw new DomainException('unknown_home');
    $expected = 'eca29e080af598f8964cfd5589b5ae3c9df72099dec3921d7b87ea143ed069e2';
    $parent = null;
    foreach ([$home.'/speakyu',$home] as $candidate) {
        $public = $candidate.'/speakyu_html';
        if (is_file($public.'/classroom.php') && hash_equals($expected,hash_file('sha256',$public.'/classroom.php'))
            && realpath($public)===$public && is_file($candidate.'/.speakyu-staff/staff.sqlite')) {
            $parent = $candidate;
            break;
        }
    }
    if ($parent === null) throw new DomainException('speakyu_root_not_found');
    $target = $parent.'/.speakyu-turn.php';
    if (file_exists($target) || is_link($target)) throw new DomainException('speakyu_config_exists');

    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $stored = Illuminate\Support\Facades\Storage::disk('local')->get('rtc/cloudflare.enc');
    if (!is_string($stored) || $stored === '') throw new DomainException('cec_turn_config_unavailable');
    $source = json_decode(Illuminate\Support\Facades\Crypt::decryptString($stored),true,flags:JSON_THROW_ON_ERROR);
    $id = $source['key'] ?? '';
    $token = $source['token'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]+$/D',$id) || !preg_match('/^[A-Za-z0-9._-]+$/D',$token)) {
        throw new DomainException('invalid_turn_config');
    }

    $ice = App\Services\ClassroomConnectivity::servers('speakyu-install:'.Illuminate\Support\Str::uuid());
    if (!App\Services\ClassroomConnectivity::hasRelay($ice)) throw new DomainException('cec_turn_unavailable');
    $temporary = $parent.'/.speakyu-turn-'.bin2hex(random_bytes(12));
    try {
        $handle = fopen($temporary,'xb');
        if (!$handle) throw new DomainException('speakyu_config_not_writable');
        $content = "<?php\nreturn ".var_export(['key'=>$id,'token'=>$token],true).";\n";
        if (fwrite($handle,$content)!==strlen($content) || !fclose($handle) || !chmod($temporary,0600)) {
            throw new DomainException('speakyu_config_not_writable');
        }
        if (!link($temporary,$target)) throw new DomainException('speakyu_config_not_writable');
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
    echo '{"configured":true}';
} catch (DomainException $error) {
    echo json_encode(['configured'=>false,'reason'=>$error->getMessage()]);
} catch (Throwable $error) {
    echo '{"configured":false,"reason":"operator_error"}';
}
