<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use SoftDeletes;

    protected $attributes = ['certificate_enabled' => true, 'cover_color' => '#eff0f4', 'cover_text_color' => '#212a5e', 'cover_opacity' => 100, 'cover_text' => 'Aa', 'cover_font' => 'sans', 'cover_text_size' => 58, 'cover_align' => 'right'];

    protected $hidden = ['cover_path'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'certificate_enabled' => 'boolean', 'duration_hours' => 'float'];
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('position')->orderBy('id');
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'enrollments')->withTimestamps();
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function resources()
    {
        return $this->hasMany(Resource::class);
    }

    public function managedBy(User $u): bool
    {
        return ! $this->trashed() && ($u->role === 'admin' || ($u->role === 'teacher' && $this->teacher_id === $u->id));
    }

    public function accessibleBy(User $u): bool
    {
        return ! $this->trashed() && ($this->managedBy($u) || ($this->published && $this->students()->where('users.id', $u->id)->exists()));
    }

    public function announcements()
    {
        return $this->hasMany(Announcement::class);
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }

        return $query->where(function ($allowed) use ($user) {
            if ($user->role === 'teacher') {
                $allowed->where('teacher_id', $user->id);
            }
            $method = $user->role === 'teacher' ? 'orWhere' : 'where';
            $allowed->$method(fn ($enrolled) => $enrolled->where('published', true)->whereHas('students', fn ($students) => $students->where('users.id', $user->id)));
        });
    }
}
