<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['publish_at' => 'datetime'];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function readers()
    {
        return $this->belongsToMany(User::class, 'announcement_reads')->withPivot('read_at');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('publish_at', '<=', now())->whereHas('course', function (Builder $course) use ($user) {
            if ($user->role === 'admin') {
                return;
            }
            $course->where(function (Builder $allowed) use ($user) {
                if ($user->role === 'teacher') {
                    $allowed->where('teacher_id', $user->id);
                }
                $method = $user->role === 'teacher' ? 'orWhere' : 'where';
                $allowed->$method(fn (Builder $enrolled) => $enrolled->where('published', true)->whereHas('students', fn (Builder $students) => $students->where('users.id', $user->id)));
            });
        });
    }

    public function scopeUnreadBy(Builder $q, User $user): Builder
    {
        return $q->whereDoesntHave('readers', fn (Builder $readers) => $readers->where('users.id', $user->id));
    }
}
