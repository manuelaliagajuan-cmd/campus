<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\StaffController;
use App\Http\Middleware\PrivateResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(PrivateResponse::class)->group(function () {
    Route::redirect('/', '/campus');
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
    Route::middleware('auth')->group(function () {
        Route::post('/salir', [AuthController::class, 'logout'])->name('logout');
        Route::get('/campus', [CampusController::class, 'dashboard'])->name('dashboard');
        Route::get('/cursos/{course}', [CampusController::class, 'course'])->name('courses.show');
        Route::get('/lecciones/{lesson}', [CampusController::class, 'lesson'])->name('lessons.show');
        Route::post('/lecciones/{lesson}/completar', [CampusController::class, 'complete'])->name('lessons.complete');
        Route::get('/biblioteca', [CampusController::class, 'library'])->name('library');
        Route::get('/archivos/{resource}', [CampusController::class, 'file'])->name('resources.file');
        Route::get('/resultados', [CampusController::class, 'results'])->name('results');
        Route::get('/docente/cursos/nuevo', [StaffController::class, 'create'])->name('staff.create');
        Route::post('/docente/cursos', [StaffController::class, 'store'])->name('staff.store');
        Route::get('/docente/cursos/{course}', [StaffController::class, 'edit'])->name('staff.edit');
        Route::put('/docente/cursos/{course}', [StaffController::class, 'update'])->name('staff.update');
        Route::get('/docente/cursos/{course}/lecciones/nueva', [StaffController::class, 'lessonForm'])->name('staff.lesson.create');
        Route::post('/docente/cursos/{course}/lecciones', [StaffController::class, 'lessonSave'])->name('staff.lesson.store');
        Route::get('/docente/cursos/{course}/lecciones/{lesson}', [StaffController::class, 'lessonForm'])->name('staff.lesson.edit');
        Route::put('/docente/cursos/{course}/lecciones/{lesson}', [StaffController::class, 'lessonSave'])->name('staff.lesson.update');
        Route::post('/docente/cursos/{course}/matriculas', [StaffController::class, 'enroll'])->name('staff.enroll');
        Route::delete('/docente/cursos/{course}/matriculas/{user}', [StaffController::class, 'revoke'])->name('staff.revoke');
        Route::post('/docente/cursos/{course}/archivos', [StaffController::class, 'upload'])->name('staff.upload');
        Route::delete('/docente/archivos/{resource}', [StaffController::class, 'deleteFile'])->name('staff.file.delete');
        Route::get('/docente/usuarios', [StaffController::class, 'users'])->name('staff.users');
        Route::put('/docente/usuarios/{user}', [StaffController::class, 'role'])->name('staff.role');
        Route::get('/docente/actividades', [ActivityController::class, 'catalog'])->name('activities.catalog');
        Route::get('/docente/actividades/nueva', [ActivityController::class, 'create'])->name('activities.create');
        Route::post('/docente/actividades', [ActivityController::class, 'save'])->name('activities.store');
        Route::get('/docente/actividades/{activity}', [ActivityController::class, 'edit'])->name('activities.edit');
        Route::put('/docente/actividades/{activity}', [ActivityController::class, 'save'])->name('activities.update');
        Route::post('/actividades/{activity}/empezar', [ActivityController::class, 'start'])->middleware('throttle:30,1')->name('activities.start');
        Route::get('/intentos/{attempt}', [ActivityController::class, 'show'])->name('attempts.show');
        Route::post('/intentos/{attempt}',[ActivityController::class, 'submit'])->name('attempts.submit');
    });
});
