<?php

namespace App\Http\Controllers;

use App\Models\OnlineSession;
use App\Services\OnlineClassrooms;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnlineController extends Controller
{
    public function index(Request $r)
    {
        $rooms = OnlineClassrooms::available($r->user());
        $sessions = OnlineSession::whereIn('scope', array_keys($rooms))->latest()->limit(50)->get();
        foreach ($sessions as $s) {
            OnlineClassrooms::expire($s);
        }$ratings = DB::table('online_ratings')->where('user_id', $r->user()->id)->pluck('stars', 'online_session_id');

        return view('online.index', compact('rooms', 'sessions', 'ratings'));
    }

    public function start(Request $r)
    {
        $d = $r->validate(['scope' => 'required|string|max:230']);
        $room = OnlineClassrooms::room($r->user(), $d['scope']);
        abort_unless($room['manage'], 403);
        $existing = OnlineSession::where('active_key', $d['scope'])->first();
        if ($existing) {
            OnlineClassrooms::expire($existing);
            if (! $existing->ended_at) {
                return redirect()->route('online.show', $existing);
            }
        }
        try {
            $s = OnlineSession::create(['scope' => $d['scope'], 'active_key' => $d['scope'], 'course_id' => $room['course']->id ?? null, 'erp_pass_id' => $room['pass']['id'] ?? null, 'erp_student_id' => $room['pass']['student_id'] ?? null, 'host_id' => $r->user()->id, 'title' => mb_substr($room['title'], 0, 180)]);
        } catch (UniqueConstraintViolationException $e) {
            $s = OnlineSession::where('active_key', $d['scope'])->firstOrFail();
        }

        return redirect()->route('online.show', $s);
    }

    public function show(Request $r, OnlineSession $session)
    {
        $room = OnlineClassrooms::access($r->user(), $session);
        OnlineClassrooms::expire($session);
        $rating = DB::table('online_ratings')->where('online_session_id', $session->id)->where('user_id', $r->user()->id)->first();
        $joined = DB::table('online_participants')->where('online_session_id', $session->id)->where('user_id', $r->user()->id)->exists();
        $reviews = $room['manage'] ? DB::table('online_ratings')->join('users', 'users.id', '=', 'online_ratings.user_id')->where('online_session_id', $session->id)->select('users.name', 'stars', 'feedback')->get() : collect();

        return view('online.room', compact('room', 'session', 'rating', 'joined', 'reviews'));
    }

    private function access(Request $r, OnlineSession $s): array
    {
        $room = OnlineClassrooms::access($r->user(), $s);
        OnlineClassrooms::expire($s);

        return $room;
    }

    private function participant(Request $r, OnlineSession $s, bool $touch = true)
    {
        $r->validate(['participant' => 'required|uuid']);
        $q = DB::table('online_participants')->where('id', $r->input('participant'))->where('online_session_id', $s->id)->where('user_id', $r->user()->id)->whereNull('left_at');
        $p = $q->first();
        abort_unless($p, 403);
        if ($touch) {
            $q->update(['seen_at' => now(), 'updated_at' => now()]);
        }

        return $p;
    }

    public function join(Request $r, OnlineSession $session)
    {
        $this->access($r, $session);
        abort_if($session->ended_at, 409, __('La sesión ha finalizado.'));
        $id = DB::transaction(function () use ($r, $session) {
            OnlineSession::whereKey($session->id)->lockForUpdate()->first();
            abort_if(DB::table('online_participants')->where('online_session_id', $session->id)->whereNull('left_at')->where('seen_at', '>', now()->subSeconds(40))->count() >= config('online.max_participants'), 409, __('La sala está completa (máximo 6 personas).'));
            $id = (string) Str::uuid();
            DB::table('online_participants')->insert(['id' => $id, 'online_session_id' => $session->id, 'user_id' => $r->user()->id, 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });

        return response()->json(['participant' => $id, 'iceServers' => config('online.ice_servers')]);
    }

    public function poll(Request $r, OnlineSession $session)
    {
        $this->access($r, $session);
        $p = $this->participant($r, $session);
        $r->validate(['after' => 'nullable|integer|min:0']);
        $peers = DB::table('online_participants')->join('users', 'users.id', '=', 'online_participants.user_id')->where('online_session_id', $session->id)->whereNull('left_at')->where('seen_at', '>', now()->subSeconds(40))->select('online_participants.id', 'users.name')->get();
        $events = DB::table('online_events')->where('online_session_id', $session->id)->where('id', '>', $r->integer('after'))->where(fn ($q) => $q->whereNull('recipient_id')->orWhere('recipient_id', $p->id))->orderBy('id')->limit(150)->get()->map(function ($e) {
            $e->payload = json_decode($e->payload, true);

            return $e;
        });

        return response()->json(['ended' => (bool) $session->ended_at, 'peers' => $peers, 'events' => $events, 'notes' => ['text' => $session->notes ?? '', 'revision' => (int) $session->notes_revision], 'time' => now()->toIso8601String()]);
    }

    public function event(Request $r, OnlineSession $session)
    {
        $room = $this->access($r, $session);
        abort_if($session->ended_at, 409);
        $p = $this->participant($r, $session);
        $d = $r->validate(['kind' => 'required|in:signal,chat,stroke,clear,sharing', 'recipient' => 'nullable|uuid', 'payload' => 'required|array']);
        abort_if(strlen(json_encode($d['payload'])) > 40000, 422);
        $payload = [];
        $recipient = null;
        switch ($d['kind']) {
            case 'signal':$recipient = $d['recipient'] ?? null;
                abort_unless($recipient && $recipient !== $p->id && DB::table('online_participants')->where('online_session_id', $session->id)->where('id', $recipient)->whereNull('left_at')->where('seen_at', '>', now()->subSeconds(40))->exists(), 422);
                $payload = validator($d['payload'], ['type' => 'required|in:offer,answer,candidate', 'sdp' => 'nullable|string|max:30000', 'candidate' => 'nullable|array', 'candidate.candidate' => 'nullable|string|max:4000', 'candidate.sdpMid' => 'nullable|string|max:100', 'candidate.sdpMLineIndex' => 'nullable|integer|min:0|max:100', 'candidate.usernameFragment' => 'nullable|string|max:256'])->validate();
                break;
            case 'sharing':$payload = validator($d['payload'], ['active' => 'required|boolean'])->validate();
                break;
            case 'chat':$payload = validator($d['payload'], ['text' => 'required|string|max:2000'])->validate();
                $payload['name'] = $r->user()->name;
                break;
            case 'stroke':$payload = validator($d['payload'], ['color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'], 'width' => 'required|integer|min:1|max:12', 'points' => 'required|array|min:2|max:256', 'points.*' => 'required|array|size:2', 'points.*.*' => 'required|numeric|between:0,1'])->validate();
                break;
            case 'clear':abort_unless($room['manage'], 403);
                break;
        }
        $id = DB::table('online_events')->insertGetId(['online_session_id' => $session->id, 'participant_id' => $p->id, 'recipient_id' => $recipient, 'kind' => $d['kind'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now()]);

        return response()->json(['id' => $id]);
    }

    public function notes(Request $r, OnlineSession $session)
    {
        $this->access($r, $session);
        $this->participant($r, $session);
        $d = $r->validate(['text' => 'nullable|string|max:20000', 'revision' => 'required|integer|min:0']);

        return DB::transaction(function () use ($session, $d) {
            $locked = OnlineSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->ended_at, 409);
            if ($locked->notes_revision !== $d['revision']) {
                return response()->json(['message' => __('Los apuntes han cambiado. Tu texto se conserva; revisa la versión compartida antes de guardar.'), 'notes' => ['text' => $locked->notes ?? '', 'revision' => (int) $locked->notes_revision]], 409);
            }$locked->update(['notes' => $d['text'] ?? '', 'notes_revision' => $locked->notes_revision + 1]);

            return response()->json(['notes' => ['text' => $locked->notes ?? '', 'revision' => (int) $locked->notes_revision]]);
        });
    }

    public function leave(Request $r, OnlineSession $session)
    {
        $this->access($r, $session);
        $p = $this->participant($r, $session, false);
        DB::table('online_participants')->where('id', $p->id)->update(['left_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function end(Request $r, OnlineSession $session)
    {
        $room = $this->access($r, $session);
        abort_unless($room['manage'], 403);
        if (! $session->ended_at) {
            $session->update(['ended_at' => now(), 'active_key' => null]);
        }

        return redirect()->route('online.show', $session);
    }

    public function rate(Request $r, OnlineSession $session)
    {
        $this->access($r, $session);
        abort_unless($session->ended_at && $r->user()->role === 'student' && DB::table('online_participants')->where('online_session_id', $session->id)->where('user_id', $r->user()->id)->exists(), 403);
        $d = $r->validate(['stars' => 'required|integer|between:1,5', 'feedback' => 'nullable|string|max:3000']);
        DB::table('online_ratings')->upsert([['online_session_id' => $session->id, 'user_id' => $r->user()->id] + $d + ['created_at' => now(), 'updated_at' => now()]], ['online_session_id', 'user_id'], ['stars', 'feedback', 'updated_at']);

        return back()->with('status', __('Gracias por valorar la sesión.'));
    }
}
