<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CourseCover
{
    public static function rules(): array
    {
        return ['cover_color' => ['sometimes', 'required', 'regex:/^#[a-fA-F0-9]{6}$/'], 'cover_text_color' => ['sometimes', 'required', 'regex:/^#[a-fA-F0-9]{6}$/'], 'cover_opacity' => 'sometimes|required|integer|min:0|max:100', 'cover_text' => 'nullable|string|max:60', 'cover_label' => 'nullable|string|max:120', 'cover_font' => 'sometimes|required|in:sans,serif,rounded', 'cover_text_size' => 'sometimes|required|integer|min:24|max:80', 'cover_align' => 'sometimes|required|in:left,center,right', 'cover_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120', 'remove_cover_image' => 'nullable|boolean'];
    }

    public static function save(Request $r, array $data, ?Course $course = null): Course
    {
        $old = $course?->cover_path;
        $path = $old;
        if ($r->boolean('remove_cover_image')) {
            $data['cover_path'] = null;
            $data['cover_mime'] = null;
            $path = null;
        }
        if ($file = $r->file('cover_image')) {
            $path = $file->store('course-covers', 'local');
            $data['cover_path'] = $path;
            $data['cover_mime'] = $file->getMimeType();
        }
        try {
            $result = DB::transaction(function () use ($course, $data) {
                if ($course) {
                    $course->update($data);

                    return $course;
                }

                return Course::create($data);
            });
        } catch (\Throwable $e) {
            if ($path && $path !== $old) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return $result;
    }

    public static function font(?string $font): string
    {
        return ['sans' => 'Arial,sans-serif', 'serif' => 'Georgia,serif', 'rounded' => 'Trebuchet MS,sans-serif'][$font ?? 'sans'] ?? 'Arial,sans-serif';
    }
}
