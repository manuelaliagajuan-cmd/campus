<?php

use App\Http\Controllers\AccessController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnlineController;
use App\Http\Controllers\PassWorkspaceController;
use App\Http\Controllers\PublicCatalogController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\TeacherScheduleController;
use App\Http\Controllers\TrashController;
use App\Http\Middleware\PrivateResponse;
use App\Http\Middleware\SyncErp;
use App\Services\OnlineLessonRecords;
use App\Services\TeacherDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::middleware(PrivateResponse::class)->group(function () {
    Route::post('/pagos/stripe/webhook', [StoreController::class, 'webhook'])->middleware('throttle:120,1')->name('store.webhook');
    Route::get('/catalogo-publico', [PublicCatalogController::class, 'catalog'])->middleware('throttle:120,1')->name('store.public-catalog');
    Route::get('/comprar/desde-web', [PublicCatalogController::class, 'enter'])->name('store.web-entry');
    Route::redirect('/', '/campus');
    Route::post('/idioma', [LocaleController::class, 'update'])->name('locale');
    Route::middleware('guest')->group(function () {
        Route::get('/acceso', fn () => (new AuthController)->form('login'))->name('login');
        Route::post('/acceso', [AuthController::class, 'login'])->middleware('throttle:6,1');
        Route::get('/registro', fn () => (new AuthController)->form('register'))->name('register');
        Route::post('/registro', [AuthController::class, 'register'])->middleware('throttle:6,1');
        Route::get('/recuperar', fn () => (new AuthController)->form('forgot'))->name('password.request');
        Route::post('/recuperar', [AuthController::class, 'forgot'])->middleware('throttle:3,1')->name('password.email');
        Route::get('/restablecer/{token}', [AuthController::class, 'resetForm'])->name('password.reset');
        Route::post('/restablecer', [AuthController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
    });
    Route::middleware(['auth', SyncErp::class])->group(function () {
        Route::get('/mi-horario', [TeacherScheduleController::class, 'index'])->name('staff.schedule');
        Route::get('/mi-horario/datos', [TeacherScheduleController::class, 'data'])->name('staff.schedule.data');
        Route::post('/mi-horario/ausencias', [TeacherScheduleController::class, 'leave'])->name('staff.schedule.leave');
        Route::get('/comprar', [StoreController::class, 'index'])->name('store.index');
        Route::get('/comprar/bonos/{type}/disponibilidad', [StoreController::class, 'availability'])->name('store.availability');
        Route::get('/comprar/bonos/{type}', [StoreController::class, 'pass'])->name('store.pass');
        Route::get('/comprar/catalogo', [StoreController::class, 'state'])->name('store.state');
        Route::post('/comprar/pagar', [StoreController::class, 'checkout'])->middleware('throttle:10,1')->name('store.checkout');
        Route::get('/mis-compras/{order}', [StoreController::class, 'order'])->name('store.order');
        Route::get('/certificados', [CertificateController::class, 'index'])->name('certificates.index');
        Route::get('/certificados/{certificate}', [CertificateController::class, 'show'])->name('certificates.show');
        Route::get('/certificados/{certificate}/pdf', [CertificateController::class, 'pdf'])->name('certificates.pdf');
        Route::post('/cursos/{course}/certificado', [CertificateController::class, 'issue'])->name('certificates.issue');
        Route::get('/notificaciones', [NotificationController::class, 'index'])->name('notifications');
        Route::get('/notificaciones/contador', [NotificationController::class, 'count'])->name('notifications.count');
        Route::post('/notificaciones/leer-todas', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notificaciones/{announcement}/leer', [NotificationController::class, 'read'])->name('notifications.read');
        Route::post('/docente/cursos/{course}/avisos', [NotificationController::class, 'store'])->name('staff.announcements.store');
        Route::delete('/docente/avisos/{announcement}', [NotificationController::class, 'destroy'])->name('staff.announcements.delete');
        Route::post('/salir', [AuthController::class, 'logout'])->name('logout');
        Route::post('/cuenta/confirmar-correo', [EmailVerificationController::class, 'send'])->middleware('throttle:3,1')->name('verification.send');
        Route::get('/cuenta/confirmar-correo/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
        Route::post('/docente/usuarios/{user}/confirmar-correo', [EmailVerificationController::class, 'confirm'])->name('staff.verify-email');
        Route::get('/docente/bonos', [PassWorkspaceController::class, 'index'])->name('passes.index');
        Route::get('/bonos/materiales/{content}/archivo', [PassWorkspaceController::class, 'file'])->name('passes.file');
        Route::put('/bonos/materiales/{content}', [PassWorkspaceController::class, 'publish'])->name('passes.publish');
        Route::delete('/bonos/materiales/{content}', [PassWorkspaceController::class, 'destroy'])->name('passes.destroy');
        Route::get('/bonos/{pass}', [PassWorkspaceController::class, 'show'])->name('passes.show');
        Route::post('/bonos/{pass}', [PassWorkspaceController::class, 'store'])->name('passes.store');
        Route::get('/clases-online', [OnlineController::class, 'index'])->name('online.index');
        Route::post('/clases-online', [OnlineController::class, 'start'])->middleware('throttle:online-start')->name('online.start');
        Route::post('/clases-online/cancelar', [OnlineController::class, 'cancel'])->middleware('throttle:online-start')->name('online.cancel');
        Route::get('/clases-online/horario', [OnlineController::class, 'scheduleState'])->name('online.schedule-state');
        Route::get('/clases-online/conexion', fn () => response()->json(['ok' => true, 'time' => now()->toIso8601String()]))->name('online.check');
        Route::get('/clases-online/{session}', [OnlineController::class, 'show'])->name('online.show');
        Route::post('/clases-online/{session}/entrar', [OnlineController::class, 'join'])->middleware('throttle:online-join')->name('online.join');
        Route::get('/clases-online/{session}/estado', [OnlineController::class, 'poll'])->middleware('throttle:online-poll')->name('online.poll');
        Route::post('/clases-online/{session}/evento', [OnlineController::class, 'event'])->middleware('throttle:online-event')->name('online.event');
        Route::post('/clases-online/{session}/apuntes', [OnlineController::class, 'notes'])->middleware('throttle:online-event')->name('online.notes');
        Route::post('/clases-online/{session}/salir', [OnlineController::class, 'leave'])->name('online.leave');
        Route::post('/clases-online/{session}/finalizar', [OnlineController::class, 'end'])->name('online.end');
        Route::post('/clases-online/{session}/valorar', [OnlineController::class, 'rate'])->middleware('throttle:online-rate')->name('online.rate');
        Route::get('/mis-accesos', [AccessController::class, 'index'])->name('accesses');
        Route::get('/campus', [CampusController::class, 'dashboard'])->name('dashboard');
        Route::get('/cursos/{course}/cabecera', [CampusController::class, 'cover'])->name('courses.cover');
        Route::get('/cursos/{course}', [CampusController::class, 'course'])->name('courses.show');
        Route::get('/lecciones/{lesson}', [CampusController::class, 'lesson'])->name('lessons.show');
        Route::post('/lecciones/{lesson}/completar', [CampusController::class, 'complete'])->name('lessons.complete');
        Route::get('/biblioteca', [CampusController::class, 'library'])->name('library');
        Route::get('/archivos/{resource}', [CampusController::class, 'file'])->name('resources.file');
        Route::get('/resultados', [CampusController::class, 'results'])->name('results');
        Route::get('/docente/cursos/nuevo', [StaffController::class, 'create'])->name('staff.create');
        Route::get('/docente/papelera', [TrashController::class, 'index'])->name('staff.trash');
        Route::post('/docente/papelera/cursos/{courseId}/restaurar', [TrashController::class, 'course'])->name('staff.course.restore');
        Route::post('/docente/papelera/actividades/{activityId}/restaurar', [TrashController::class, 'activity'])->name('activities.restore');
        Route::delete('/docente/cursos/{course}', [StaffController::class, 'destroy'])->name('staff.destroy');
        Route::post('/docente/cursos', [StaffController::class, 'store'])->name('staff.store');
        Route::get('/docente/cursos/{course}', [StaffController::class, 'edit'])->name('staff.edit');
        Route::put('/docente/cursos/{course}', [StaffController::class, 'update'])->name('staff.update');
        Route::get('/docente/cursos/{course}/lecciones/nueva', [StaffController::class, 'lessonForm'])->name('staff.lesson.create');
        Route::post('/docente/cursos/{course}/lecciones', [StaffController::class, 'lessonSave'])->name('staff.lesson.store');
        Route::get('/docente/cursos/{course}/lecciones/{lesson}', [StaffController::class, 'lessonForm'])->name('staff.lesson.edit');
        Route::put('/docente/cursos/{course}/lecciones/{lesson}', [StaffController::class, 'lessonSave'])->name('staff.lesson.update');
        Route::post('/docente/cursos/{course}/matriculas', [StaffController::class, 'enroll'])->name('staff.enroll');
        Route::delete('/docente/cursos/{course}/matriculas/{user}', [StaffController::class, 'revoke'])->name('staff.revoke');
        Route::post('/docente/cursos/{course}/audios', [StaffController::class, 'audio'])->name('staff.audio');
        Route::post('/docente/cursos/{course}/imagenes', [StaffController::class, 'image'])->name('staff.images');
        Route::post('/docente/cursos/{course}/archivos', [StaffController::class, 'upload'])->name('staff.upload');
        Route::delete('/docente/archivos/{resource}', [StaffController::class, 'deleteFile'])->name('staff.file.delete');
        Route::get('/docente/usuarios', [StaffController::class, 'users'])->name('staff.users');
        Route::put('/docente/usuarios/{user}', [StaffController::class, 'role'])->name('staff.role');
        Route::get('/docente/actividades', [ActivityController::class, 'catalog'])->name('activities.catalog');
        Route::get('/docente/actividades/nueva', [ActivityController::class, 'create'])->name('activities.create');
        Route::post('/docente/actividades', [ActivityController::class, 'save'])->name('activities.store');
        Route::get('/docente/actividades/{activity}', [ActivityController::class, 'edit'])->name('activities.edit');
        Route::delete('/docente/actividades/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
        Route::put('/docente/actividades/{activity}', [ActivityController::class, 'save'])->name('activities.update');
        Route::get('/actividades/{activity}', [ActivityController::class, 'overview'])->name('activities.overview');
        Route::post('/actividades/{activity}/empezar', [ActivityController::class, 'start'])->middleware('throttle:30,1')->name('activities.start');
        Route::get('/intentos/{attempt}', [ActivityController::class, 'show'])->name('attempts.show');
        Route::post('/intentos/{attempt}', [ActivityController::class, 'submit'])->name('attempts.submit');
    });
});

Route::post('/interno/clases/procesar', function (Request $r) {
    $time = $r->header('X-CEC-Time', '');
    $key = config('erp.jobs_key');
    abort_unless($key && ctype_digit($time) && abs(time() - (int) $time) <= 300 && hash_equals(hash_hmac('sha256', $time, $key), $r->header('X-CEC-Signature', '')), 403);
    $lock = Cache::lock('cec-lesson-reconcile', 120);
    if (! $lock->get()) {
        return response()->json(['busy' => true]);
    }
    try {
        OnlineLessonRecords::reconcile();
    } finally {
        $lock->release();
    }

    return response()->json(['ok' => true]);
});

Route::post('/interno/profesores/sincronizar', function (Request $r) {
    $time = $r->header('X-CEC-Time', '');
    $key = config('erp.jobs_key');
    abort_unless($key && ctype_digit($time) && abs(time() - (int) $time) <= 300 && hash_equals(hash_hmac('sha256', $time, $key), $r->header('X-CEC-Signature', '')), 403);
    $lock = Cache::lock('cec-teacher-directory', 90);
    if (! $lock->get()) {
        return response()->json(['busy' => true]);
    }try {
        TeacherDirectory::sync();
    } finally {
        $lock->release();
    }

    return response()->json(['ok' => true]);
});
