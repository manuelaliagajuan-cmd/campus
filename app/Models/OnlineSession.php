<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ended_at' => 'datetime', 'notes_revision' => 'integer'];
    }
}
