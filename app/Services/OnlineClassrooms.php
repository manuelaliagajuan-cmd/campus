<?php

namespace App\Services;

use App\Models\Course;
use App\Models\OnlineSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
            if (($p['online_enabled'] ?? false) === true && $p['remaining'] > 0 && (! $p['expires'] || $p['expires'] >= $today)) {
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
        self::expire($s);
        $room = self::available($u)[$s->scope] ?? null;
        if (! $s->ended_at && $s->booking_id && ($room || $s->host_id === $u->id || DB::table('online_participants')->where('online_session_id', $s->id)->where('user_id', $u->id)->exists())) {
            $entry = $room ? ScheduledClasses::find($room, $s->booking_id) : null;
            if (! $entry || $entry['signature'] !== $s->booking_signature) {
                $s->update(['ended_at' => now(), 'active_key' => null]);
            }
        }
        if ($s->ended_at) {
            $own = DB::table('online_participants')->where('online_session_id', $s->id)->where('user_id', $u->id)->exists();
            abort_unless($own || $s->host_id === $u->id || $u->role === 'admin', 403);

            return $room ?? ['key' => $s->scope, 'title' => $s->title, 'manage' => $u->role === 'admin' || $s->host_id === $u->id];
        }
        abort_unless($room, 403);
        if ($s->booking_id) {
            $entry = ScheduledClasses::find($room, $s->booking_id);
            abort_unless($entry && now()->lte($entry['closes']), 403);
        }

        return $room;
    }

    public static function expire(OnlineSession $s): void
    {
        if (! $s->ended_at && ((! $s->booking_end_at && $s->created_at->lt(now()->subHours(6))) || ($s->booking_end_at && $s->booking_end_at->copy()->addMinutes(15)->lt(now())))) {
            $s->update(['ended_at' => now(), 'active_key' => null]);
        }
    }
}
