<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Questions
{
    public static function validate(mixed $raw): array
    {
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        Validator::make(['questions' => $data], ['questions' => 'required|array|min:1|max:50', 'questions.*' => 'required|array', 'questions.*.type' => 'required|in:multiple,truefalse,cloze,matching,ordering', 'questions.*.prompt' => 'required|string|max:2000', 'questions.*.explanation' => 'nullable|string|max:3000'])->validate();
        $out = [];
        foreach ($data as $q) {
            $base = ['type' => $q['type'], 'prompt' => $q['prompt'], 'explanation' => $q['explanation'] ?? ''];
            switch ($q['type']) {
                case 'multiple':case 'truefalse':if ($q['type'] === 'truefalse') {
                    $q['options'] = ['Verdadero', 'Falso'];
                }Validator::make($q, ['options' => 'required|array|list|min:2|max:8', 'options.*' => 'required|string|max:500', 'correct' => 'required|integer|min:0|max:'.(count($q['options'] ?? []) - 1)])->validate();
                    $base += ['options' => array_values($q['options']), 'correct' => (int) $q['correct']];
                    break;
                case 'cloze':Validator::make($q, ['text' => 'required|string|max:5000'])->validate();
                    preg_match_all('/\[\[([^\[\]]+)\]\]/u', $q['text'], $m);
                    if (count($m[1]) < 1 || count($m[1]) > 15) {
                        throw ValidationException::withMessages(['questions' => __('El texto debe contener entre 1 y 15 huecos con formato [[respuesta]].')]);
                    }$base['text'] = $q['text'];
                    break;
                case 'matching':Validator::make($q, ['pairs' => 'required|array|list|min:2|max:15', 'pairs.*' => 'required|array|list|size:2', 'pairs.*.*' => 'required|string|max:250'])->validate();
                    $base['pairs'] = array_values($q['pairs']);
                    break;
                case 'ordering':Validator::make($q, ['items' => 'required|array|list|min:2|max:15', 'items.*' => 'required|string|max:250'])->validate();
                    $base['items'] = array_values($q['items']);
                    break;
            }$out[] = $base;
        }

        return $out;
    }
}
