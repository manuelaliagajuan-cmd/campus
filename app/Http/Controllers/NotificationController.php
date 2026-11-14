<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $r)
    {
        $announcements = Announcement::visibleTo($r->user())->with(['course', 'author', 'readers' => fn ($q) => $q->where('users.id', $r->user()->id)])->latest('publish_at')->paginate(20);

        return view('notifications', compact('announcements'));
    }

    public function count(Request $r)
    {
        return response()->json(['count' => Announcement::visibleTo($r->user())->unreadBy($r->user())->count()]);
    }

    public function read(Request $r, Announcement $announcement)
    {
        abort_unless(Announcement::visibleTo($r->user())->whereKey($announcement->id)->exists(), 403);
        DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $announcement->id, 'user_id' => $r->user()->id, 'read_at' => now()]);

        return back()->with('status', __('Aviso marcado como leído.'));
    }

    public function readAll(Request $r)
    {
        $ids = Announcement::visibleTo($r->user())->unreadBy($r->user())->pluck('id');
        foreach ($ids as $id) {
            DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $id, 'user_id' => $r->user()->id, 'read_at' => now()]);
        }

        return back()->with('status', __('Avisos marcados como leídos.'));
    }

    public function store(Request $r, Course $course)
    {
        abort_unless($course->managedBy($r->user()), 403);
        $d = $r->validate(['title' => 'required|string|max:180', 'body' => 'required|string|max:5000', 'kind' => 'required|in:notice,reminder', 'publish_at' => 'nullable|date']);
        $d['publish_at'] = ($d['publish_at'] ?? null) ?: now();
        $d['author_id'] = $r->user()->id;
        DB::transaction(function () use ($course, $r, $d) {
            $a = $course->announcements()->create($d);
            $a->readers()->attach($r->user()->id, ['read_at' => now()]);
        });

        return back()->with('status', __('Aviso guardado. Los alumnos lo verán en la fecha de publicación.'));
    }

    public function destroy(Request $r, Announcement $announcement)
    {
        abort_unless($announcement->course->managedBy($r->user()), 403);
        $announcement->delete();

        return back()->with('status', __('Aviso eliminado.'));
    }
}
