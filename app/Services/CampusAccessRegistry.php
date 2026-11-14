<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class CampusAccessRegistry
{
    private static function directory(): ?string
    {
        $source = config('erp.projection_path');

        return $source ? dirname($source) : null;
    }

    private static function account(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => strtolower(trim($user->email)), 'registered_at' => $user->created_at->toIso8601String(), 'email_verified' => $user->hasVerifiedEmail(), 'student_id' => $user->erp_student_id];
    }

    private static function write(callable $change): void
    {
        $dir = self::directory();
        if (! $dir) {
            return;
        }
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Registry storage unavailable');
        }
        $lock = fopen($dir.'/campus-accounts.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Registry storage busy');
        }
        $temp = null;
        try {
            $path = $dir.'/campus-accounts.php';
            $accounts = [];
            if (is_file($path)) {
                $raw = file_get_contents($path);
                $prefix = "<?php exit; ?>\n";
                if (! str_starts_with($raw, $prefix)) {
                    throw new RuntimeException('Invalid registry');
                }
                $accounts = json_decode(substr($raw, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR)['accounts'];
            }
            $next = $change($accounts);
            if ($accounts === $next && is_file($path)) {
                return;
            }
            $value = ['schema' => 1, 'updated_at' => now()->toIso8601String(), 'accounts' => array_values($next)];
            $raw = "<?php exit; ?>\n".json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $temp = tempnam($dir, 'campus-accounts-');
            chmod($temp, 0600);
            if (file_put_contents($temp, $raw) !== strlen($raw) || ! rename($temp, $path)) {
                throw new RuntimeException('Registry write failed');
            }
        } finally {
            if ($temp && is_file($temp)) {
                unlink($temp);
            }flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function publish(User $user): void
    {
        $user->refresh();
        self::write(function ($accounts) use ($user) {
            $rows = collect($accounts)->keyBy('id');
            if ($user->role === 'student') {
                $rows->put($user->id, self::account($user));
            } else {
                $rows->forget($user->id);
            }

            return $rows->sortKeys()->values()->all();
        });
    }

    public static function publishAll(): void
    {
        self::write(fn () => User::where('role', 'student')->orderBy('id')->get()->map(fn ($u) => self::account($u))->all());
    }

    public static function safelyPublish(User $user): void
    {
        try {
            self::publish($user);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function review(User $user): ?array
    {
        $dir = self::directory();
        if (! $dir || ! is_file($dir.'/campus-links.php')) {
            return null;
        }
        $raw = file_get_contents($dir.'/campus-links.php');
        $prefix = "<?php exit; ?>\n";
        if (! str_starts_with($raw, $prefix)) {
            throw new RuntimeException('Invalid link review');
        }
        $value = json_decode(substr($raw, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        if (($value['schema'] ?? null) !== 1 || ! is_array($value['links'] ?? null)) {
            throw new RuntimeException('Invalid link review');
        }
        $review = $value['links'][(string) $user->id] ?? null;
        if ($review && (! is_string($review['student_id'] ?? null) || ! is_string($review['account_email'] ?? null) || ! is_string($review['review_id'] ?? null) || ! is_string($review['checked_by'] ?? null) || ! is_string($review['reviewed_at'] ?? null))) {
            throw new RuntimeException('Invalid link review');
        }

        return $review;
    }
}
