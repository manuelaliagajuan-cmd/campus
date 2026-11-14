<?php

namespace App\Services;

use App\Models\Course;
use App\Models\OnlineSession;
use App\Models\User;

class OnlineClassrooms
{
    public static function available(User $u): array
    {
        $rooms = [];
        foreach (Course::where('online_enabled', true)->get() as $c) {
            if ($c->accessibleBy($u)) {
                $rooms['course:'.$c->id] = ['key' => 'course:'.$c->id, 'title' => $c->title, 'manage' => $c->managedBy($u), 'course' => $c];
            }
        }$today = now('Europe/Madrid')->toDateString();
        foreach (ErpConnection::workspaces($u) as $p) {
            if (($p['online_enabled'] ?? false) === true && $p['remaining'] > 0 && (! $p['start'] || $p['start'] <= $today) && (! $p['expires'] || $p['expires'] >= $today)) {
                $key = 'pass:'.$p['id'].':'.$p['student_id'];
                $rooms[$key] = ['key' => $key, 'title' => $p['name'].' · '.$p['student_name'], 'manage' => $p['manage'], 'pass' => $p];
            }
        }

        return $rooms;
    }

    public static function room(User $u, string $key): array
    {
        $room = self::available($u)[$key] ?? null;
        abort_unless($room, 403);

        return $room;
    }

    public static function access(User $u, OnlineSession $s): array
    {
        return self::room($u, $s->scope);
    }

    public static function expire(OnlineSession $s): void
    {
        if (! $s->ended_at && $s->created_at->lt(now()->subHours(6))) {
            $s->update(['ended_at' => now(), 'active_key' => null]);
        }
    }
}
