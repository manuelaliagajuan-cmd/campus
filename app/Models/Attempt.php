<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attempt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['questions', 'answers', 'feedback'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'answers' => 'array', 'feedback' => 'array', 'submitted_at' => 'datetime'];
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
