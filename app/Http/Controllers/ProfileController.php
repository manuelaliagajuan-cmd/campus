<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\User;
use App\Services\ErpConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function edit(Request $r)
    {
        return view('profile', ['profile' => $r->user()]);
    }

    public function update(Request $r)
    {
        $d = $r->validate(['display_name' => 'nullable|string|max:80', 'bio' => 'nullable|string|max:1000', 'appearance' => 'required|in:system,light,dark', 'accent' => 'required|in:blue,violet,teal,rose', 'density' => 'required|in:comfortable,compact', 'text_size' => 'required|in:normal,large', 'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=6000,max_height=6000', 'remove_avatar' => 'nullable|boolean']);
        $file = $r->file('avatar');
        $path = $file ? $file->store('avatars/'.$r->user()->id, 'local') : null;
        $old = null;
        $resultPath = null;
        unset($d['avatar'],$d['remove_avatar']);
        $d['display_name'] = trim($d['display_name'] ?? '') ?: null;
        try {
            DB::transaction(function () use ($r, $d, $file, $path, &$old, &$resultPath) {
                $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
                $old = $u->avatar_path;
                $data = $d;
                if ($r->boolean('remove_avatar')) {
                    $data += ['avatar_path' => null, 'avatar_mime' => null];
                }if ($file) {
                    $data['avatar_path'] = $path;
                    $data['avatar_mime'] = $file->getMimeType();
                }$u->forceFill($data)->save();
                $resultPath = $u->avatar_path;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
        if ($old && $old !== $resultPath) {
            Storage::disk('local')->delete($old);
        }

        return redirect()->route('profile.edit')->with('status', __('Tu perfil se ha actualizado.'));
    }

    public function avatar(Request $r, User $user)
    {
        $viewer = $r->user();
        $allowed = $viewer->id === $user->id || $viewer->role === 'admin';
        if (! $allowed && $user->isStaff()) {
            $allowed = Course::where('teacher_id', $user->id)->where('published', true)->whereHas('students', fn ($q) => $q->whereKey($viewer->id))->exists();
            if (! $allowed) {
                $allowed = collect(ErpConnection::workspaces($viewer))->contains(fn ($p) => ($p['teacher_id'] ?? null) === $user->id);
            }
        }
        if (! $allowed && $viewer->isStaff() && $user->role === 'student') {
            $allowed = Course::where('teacher_id', $viewer->id)->whereHas('students', fn ($q) => $q->whereKey($user->id))->exists();
            if (! $allowed && $user->erp_student_id) {
                $allowed = collect(ErpConnection::workspaces($viewer))->contains(fn ($p) => $p['student_id'] === $user->erp_student_id);
            }
        }
        abort_unless($allowed && $user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, ['Content-Type' => $user->avatar_mime ?? 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }
}
