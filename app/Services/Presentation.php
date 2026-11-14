<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Presentation
{
    public static function validate(Course $course, ?string $raw): array
    {
        if (strlen($raw ?? '') > 150000) {
            throw ValidationException::withMessages(['presentation' => __('La presentación es demasiado grande.')]);
        }
        try {
            $slides = json_decode($raw ?? '', true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ValidationException::withMessages(['presentation' => __('Revisa las diapositivas de la presentación.')]);
        }
        $d = Validator::make(['slides' => $slides], ['slides' => 'required|array|min:1|max:40', 'slides.*.title' => 'nullable|string|max:180', 'slides.*.blocks' => 'required|array|min:1|max:15', 'slides.*.blocks.*.type' => 'required|in:text,image,video,activity'])->validate();
        $result = [];
        foreach ($slides as $slide) {
            $blocks = [];
            foreach ($slide['blocks'] as $block) {
                switch ($block['type']) {
                    case 'text':$value = Validator::make($block, ['body' => 'required|string|max:20000'])->validate();
                        $blocks[] = ['type' => 'text', 'body' => $value['body']];
                        break;
                    case 'image':$value = Validator::make($block, ['resource_id' => 'required|integer'])->validate();
                        $image = $course->resources()->whereKey($value['resource_id'])->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp'])->first();
                        if (! $image) {
                            throw ValidationException::withMessages(['presentation' => __('Selecciona una imagen de este curso.')]);
                        }$blocks[] = ['type' => 'image', 'resource_id' => $image->id];
                        break;
                    case 'video':$youtube = YouTube::id($block['url'] ?? null);
                        if (! $youtube) {
                            throw ValidationException::withMessages(['presentation' => __('Introduce un enlace de YouTube válido.')]);
                        }$blocks[] = ['type' => 'video', 'url' => 'https://www.youtube.com/watch?v='.$youtube, 'youtube_id' => $youtube];
                        break;
                    case 'activity':$value = Validator::make($block, ['activity_id' => 'required|integer'])->validate();
                        $activity = $course->activities()->whereKey($value['activity_id'])->where('published', true)->first();
                        if (! $activity) {
                            throw ValidationException::withMessages(['presentation' => __('Selecciona una actividad publicada de este curso.')]);
                        }$blocks[] = ['type' => 'activity', 'activity_id' => $activity->id];
                        break;
                }
            }$result[] = ['title' => $slide['title'] ?? '', 'blocks' => $blocks];
        }

        return $result;
    }

    public static function activityIds(?array $slides): array
    {
        return collect($slides ?? [])->flatMap(fn ($s) => $s['blocks'])->where('type', 'activity')->pluck('activity_id')->unique()->all();
    }

    public static function display(Lesson $lesson): array
    {
        $images = $lesson->course->resources()->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp'])->get()->keyBy('id');
        $activities = $lesson->course->activities()->where('published', true)->get()->keyBy('id');

        return collect($lesson->presentation ?? [])->map(function ($s) use ($images, $activities) {
            $s['blocks'] = array_map(function ($b) use ($images, $activities) {
                if ($b['type'] === 'image') {
                    $b['resource'] = $images->get($b['resource_id']);
                }if ($b['type'] === 'activity') {
                    $b['activity'] = $activities->get($b['activity_id']);
                }

return $b;
            }, $s['blocks']);

            return $s;
        })->all();
    }
}
