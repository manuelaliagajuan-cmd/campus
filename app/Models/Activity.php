<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['questions'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'published' => 'boolean'];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lessons()
    {
        return $this->belongsToMany(Lesson::class);
    }

    public function attempts()
    {
        return $this->hasMany(Attempt::class);
    }
}
