<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $attributes = ['certificate_enabled' => true];

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
        return $u->role === 'admin' || ($u->role === 'teacher' && $this->teacher_id === $u->id);
    }

    public function accessibleBy(User $u): bool
    {
        return $this->managedBy($u) || ($this->published && $this->students()->where('users.id', $u->id)->exists());
    }

    public function announcements()
    {
        return $this->hasMany(Announcement::class);
    }
}
