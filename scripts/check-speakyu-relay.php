<?php
declare(strict_types=1);

ini_set('display_errors','0');
try {
    $home = rtrim((string)getenv('HOME'),'/');
    foreach ([$home.'/speakyu',$home] as $candidate) {
        $public = $candidate.'/speakyu_html';
        if (!is_file($candidate.'/.speakyu-turn.php') || !is_file($public.'/classroom.php')) continue;
        if (!hash_equals('eca29e080af598f8964cfd5589b5ae3c9df72099dec3921d7b87ea143ed069e2',
            hash_file('sha256',$public.'/classroom.php'))) continue;
        define('SPEAKYU_CLASSROOM',true);
        require $public.'/classroom.php';
        $state = classroomIce();
        echo json_encode(['configured'=>true,'relay'=>$state['relayAvailable'],'iceServers'=>$state['iceServers']],JSON_THROW_ON_ERROR);
        exit;
    }
} catch (Throwable $error) {}
echo '{"configured":false,"relay":false}';
