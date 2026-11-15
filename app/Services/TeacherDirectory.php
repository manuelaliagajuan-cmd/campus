<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class TeacherDirectory
{
    public static function core(callable $callback): mixed
    {
        $core = config('erp.teachers_core');
        if (! $core || ! is_file($core)) {
            throw new \RuntimeException('El directorio de profesores de Gestión no está disponible.');
        }require_once $core;
        $prior = getenv('CEC_ACADEMIC_STORE_DIR');
        putenv('CEC_ACADEMIC_STORE_DIR='.dirname(config('erp.projection_path')));
        try {
            return $callback();
        } finally {
            $prior === false ? putenv('CEC_ACADEMIC_STORE_DIR') : putenv('CEC_ACADEMIC_STORE_DIR='.$prior);
        }
    }

    private static function invitations(callable $change): void
    {
        $dir = dirname(config('erp.projection_path'));
        $lock = fopen($dir.'/campus-teacher-access.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Accesos ocupados.');
        }$temp = null;
        try {
            $path = $dir.'/campus-teacher-access.php';
            $rows = is_file($path) ? json_decode(substr(file_get_contents($path), 15), true, 512, JSON_THROW_ON_ERROR) : [];
            $rows = $change($rows);
            $temp = tempnam($dir, 'teacher-access-');
            chmod($temp, 0600);
            file_put_contents($temp, "<?php exit; ?>\n".json_encode($rows, JSON_THROW_ON_ERROR));
            if (! rename($temp, $path)) {
                throw new \RuntimeException('No se pudo guardar el acceso.');
            }
        } finally {
            if ($temp && is_file($temp)) {
                unlink($temp);
            }flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function sync(): void
    {
        if (! is_file(config('erp.teachers_core') ?? '')) {
            return;
        }$profiles = self::core(fn () => \campus_teacher_profiles());
        foreach ($profiles as $profile) {
            if (empty($profile['createCampus']) || ($profile['active'] ?? true) === false) {
                continue;
            }$email = strtolower(trim($profile['email']));
            try {
                $user = DB::transaction(function () use ($profile, $email) {
                    $u = User::where('erp_teacher_id', $profile['id'])->lockForUpdate()->first();
                    if (! $u && ! empty($profile['campusId'])) {
                        $u = User::whereKey($profile['campusId'])->lockForUpdate()->first();
                        if (! $u || ! $u->isStaff() || strtolower($u->email) !== $email || ($u->erp_teacher_id && $u->erp_teacher_id !== $profile['id'])) {
                            throw new \InvalidArgumentException('Revisa la cuenta docente seleccionada.');
                        }
                    }
                    if (! $u) {
                        $existing = User::where('email', $email)->first();
                        if ($existing) {
                            throw new \InvalidArgumentException('Ya existe una cuenta con este correo. Selecciona su cuenta docente o revisa sus permisos desde el campus.');
                        }$u = User::forceCreate(['name' => $profile['name'], 'email' => $email, 'password' => Hash::make(Str::random(64)), 'role' => 'teacher', 'erp_teacher_id' => $profile['id'], 'teacher_access_pending' => true]);
                    }
                    if (! $u->isStaff() || strtolower($u->email) !== $email) {
                        throw new \InvalidArgumentException('La cuenta ha cambiado. Revisa el vínculo antes de continuar.');
                    }$u->forceFill(['erp_teacher_id' => $profile['id'], 'name' => $profile['name']])->save();

                    return $u;
                });
                CampusAccessRegistry::publish($user);
                if ($user->teacher_access_pending) {
                    $needs = false;
                    self::invitations(function ($rows) use ($profile, &$needs) {
                        $entry = $rows[$profile['id']] ?? null;
                        $needs = ! $entry || strtotime($entry['expires_at']) < time() || ! empty($profile['renewAccess']);

                        return $rows;
                    });
                    if ($needs) {
                        $token = Password::createToken($user);
                        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
                        $entry = ['url' => $url, 'email' => $email, 'expires_at' => now()->addMinutes(config('auth.passwords.users.expire', 60))->toIso8601String(), 'user_id' => $user->id];
                        self::invitations(function ($rows) use ($profile, $entry) {
                            $rows[$profile['id']] = $entry;

                            return $rows;
                        });
                    }
                    self::core(fn () => \campus_teacher_profile_update($profile['id'], $email, ['campusId' => $user->id, 'campusStatus' => 'invited', 'campusError' => '', 'renewAccess' => false]));
                } else {
                    self::core(fn () => \campus_teacher_profile_update($profile['id'], $email, ['campusId' => $user->id, 'campusStatus' => 'active', 'campusError' => '', 'renewAccess' => false]));
                    self::invitations(function ($rows) use ($profile) {
                        unset($rows[$profile['id']]);

                        return $rows;
                    });
                }
            } catch (\InvalidArgumentException $e) {
                self::core(fn () => \campus_teacher_profile_update($profile['id'], $email, ['campusStatus' => 'review', 'campusError' => $e->getMessage()]));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public static function agenda(User $user, ?int $teacherId, string $month): array
    {
        abort_unless($user->isStaff(), 403);
        $id = $teacherId ?? $user->id;
        abort_unless($user->role === 'admin' || $id === $user->id, 403);

        $data = self::core(fn () => \campus_teacher_agenda($id, $month));
        $data['courses'] = Course::where('teacher_id', $id)->withCount('students')->get()->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'students' => $c->students_count, 'online' => (bool) $c->online_enabled])->all();

        return $data;
    }

    public static function leave(User $user, array $input): void
    {
        abort_unless($user->isStaff(), 403);
        self::core(fn () => \campus_teacher_leave($user->id, $input));
    }
}
