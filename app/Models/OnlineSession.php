<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attendance_settled_at' => 'datetime', 'teacher_seen_at' => 'datetime', 'consumed_at' => 'datetime', 'ended_at' => 'datetime', 'notes_revision' => 'integer', 'booking_start_at' => 'datetime', 'booking_end_at' => 'datetime'];
    }
}
