<?php
// Only for the isolated CI/browser database. Never run against production.
if (getenv('APP_ENV') !== 'testing' || !getenv('CEC_BROWSER_FIXTURE')) {exit(1);}
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$teacher=App\Models\User::factory()->create(['email'=>'classroom-teacher@example.test','name'=>'Profesor navegador','password'=>'DemoCampus12345','role'=>'teacher']);
$student=App\Models\User::factory()->create(['email'=>'classroom-student@example.test','name'=>'Alumno navegador','password'=>'DemoCampus12345','role'=>'student']);
$course=App\Models\Course::create(['title'=>'Clase online de prueba','teacher_id'=>$teacher->id,'published'=>true,'online_enabled'=>true,'language'=>'Inglés','level'=>'B1']);$course->students()->attach($student,['source_manual'=>true]);
file_put_contents(getenv('CEC_BROWSER_FIXTURE'),json_encode(['course'=>$course->id,'student'=>$student->id]));
