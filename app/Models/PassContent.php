<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassContent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
