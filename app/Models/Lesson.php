<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $guarded = ['id'];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function activities()
    {
        return $this->belongsToMany(Activity::class);
    }

    public function completions()
    {
        return $this->hasMany(Completion::class);
    }
}
