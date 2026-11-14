<?php

use App\Models\User;
use App\Services\CampusAccessRegistry;
use App\Services\ErpConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('cec:admin {email}', function () {
    $u = User::where('email', $this->argument('email'))->first();
    if (! $u) {
        $this->error('La cuenta debe registrarse primero en el campus.');

        return 1;
    }$u->role = 'admin';
    $u->save();
    $this->info('Cuenta de administración habilitada.');

    return 0;
})->purpose('Convertir una cuenta registrada en administradora desde una sesión autorizada');

Artisan::command('cec:empty-database', function () {
    if (count(Schema::getTableListing()) !== 0) {
        $this->error('La primera instalación requiere una base de datos vacía.');

        return 1;
    }$this->info('CEC_BASE_VACIA_CONFIRMADA');

    return 0;
});
Artisan::command('cec:health', function () {
    DB::select('SELECT 1');
    foreach (['users', 'courses', 'activities', 'attempts', 'sessions', 'certificates', 'announcements', 'announcement_reads'] as $table) {
        if (! Schema::hasTable($table)) {
            return 1;
        }
    }if (__('validation.required') === 'validation.required') {
        $this->error('Faltan las traducciones de validación.');

        return 1;
    }
    $this->info('CEC_BASE_DATOS_OK');

    return 0;
});

Artisan::command('cec:publish-accesses', function () {
    CampusAccessRegistry::publishAll();
    $this->info('CEC_REGISTRO_ACCESOS_PUBLICADO');

    return 0;
})->purpose('Publicar las cuentas de estudiantes para revisión privada desde Gestión');

Artisan::command('cec:erp-diagnostics', function () {
    $this->line(json_encode(ErpConnection::diagnostics(), JSON_THROW_ON_ERROR));

    return 0;
})->purpose('Comprobar la conexión de Gestión sin mostrar nombres, correos ni saldos');
