<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function audio()
    {
        return $this->belongsTo(Resource::class, 'audio_resource_id');
    }

    protected $hidden = ['questions'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'published' => 'boolean'];
    }

    public function course()
    {
        return $this->belongsTo(Course::class)->withTrashed();
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
