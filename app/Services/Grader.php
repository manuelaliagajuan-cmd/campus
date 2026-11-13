<?php

namespace App\Services;

class Grader
{
    public static function normalize(mixed $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim(is_scalar($s) ? (string) $s : '')));
    }

    public static function grade(array $questions, array $answers): array
    {
        $total = 0;
        $feedback = [];
        foreach ($questions as $i => $q) {
            $a = $answers[$i] ?? null;
            $right = 0;
            $expected = [];
            switch ($q['type']) {
                case 'multiple':case 'truefalse':$right = is_scalar($a) && (string) $a === (string) $q['correct'] ? 1 : 0;
                    $expected = [$q['options'][$q['correct']]];
                    break;
                case 'cloze':preg_match_all('/\[\[(.*?)\]\]/u', $q['text'], $m);
                    $expected = $m[1];
                    foreach ($expected as $n => $word) {
                        if (is_array($a) && self::normalize($a[$n] ?? null) === self::normalize($word)) {
                            $right++;
                        }
                    }$right /= max(1, count($expected));
                    break;
                case 'matching':$expected = array_column($q['pairs'], 1);
                    foreach ($q['pairs'] as $n => $pair) {
                        if (is_array($a) && self::normalize($a[$n] ?? null) === self::normalize($pair[1])) {
                            $right++;
                        }
                    }$right /= count($q['pairs']);
                    break;
                case 'ordering':$expected = $q['items'];
                    if (is_array($a)) {
                        foreach ($expected as $n => $item) {
                            if (self::normalize($a[$n] ?? null) === self::normalize($item)) {
                                $right++;
                            }
                        }
                    }$right /= count($expected);
                    break;
            }
            $total += $right;
            $feedback[] = ['prompt' => $q['prompt'], 'correct' => $right >= 1, 'fraction' => round($right * 100), 'expected' => $expected, 'explanation' => $q['explanation'] ?? ''];
        }

return ['score' => round(100 * $total / max(1, count($questions)), 2), 'feedback' => $feedback];
    }

    public static function publicQuestion(array $q, int $seed): array
    {
        unset($q['correct'],$q['explanation']);
        if ($q['type'] === 'cloze') {
            $q['segments'] = preg_split('/\[\[(.*?)\]\]/u', $q['text']);
            $q['text'] = preg_replace('/\[\[(.*?)\]\]/u', '_____', $q['text']);
        }
        if ($q['type'] === 'matching') {
            $q['left'] = array_column($q['pairs'], 0);
            $q['choices'] = array_column($q['pairs'], 1);
            unset($q['pairs']);
            $original = $q['choices'];
            usort($q['choices'], fn ($a, $b) => strcmp(hash('sha256', $seed.$a), hash('sha256', $seed.$b)));
            if (count($original) > 1 && $q['choices'] === $original) {
                $q['choices'] = array_merge(array_slice($original, 1), [$original[0]]);
            }
        }
        if ($q['type'] === 'ordering') {
            $q['choices'] = $q['items'];
            unset($q['items']);
            $original = $q['choices'];
            usort($q['choices'], fn ($a, $b) => strcmp(hash('sha256', $seed.$a), hash('sha256', $seed.$b)));
            if (count($original) > 1 && $q['choices'] === $original) {
                $q['choices'] = array_merge(array_slice($original,1),[$original[0]]);
            }
        }

        return $q;
    }
}
