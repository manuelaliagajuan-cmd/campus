<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LearningAudio
{
    public const MIMES = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'application/ogg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/webm'];

    public static function selected(Request $r, Course $course): ?int
    {
        $d = $r->validate(['audio_resource_id' => 'nullable|integer']);
        $id = $d['audio_resource_id'] ?? null;
        if ($id && ! $course->resources()->whereKey($id)->whereIn('mime', self::MIMES)->exists()) {
            throw ValidationException::withMessages(['audio_resource_id' => __('Selecciona un audio de este curso.')]);
        }

        return $id ? (int) $id : null;
    }
}
