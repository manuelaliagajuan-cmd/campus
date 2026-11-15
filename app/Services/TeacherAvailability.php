<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class TeacherAvailability
{
    public static function month(array $product, string $month, ?string $subject = null, ?int $teacher = null): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month, 'Europe/Madrid');
        abort_unless($start && $start->format('Y-m') === $month && $start->gte(now('Europe/Madrid')->startOfMonth()) && $start->lte(now('Europe/Madrid')->startOfMonth()->addMonthsNoOverflow(12)), 422);
        $source = ErpConnection::snapshot();
        $feed = $source['availability'] ?? ['teachers' => [], 'weekly' => [], 'busy' => []];
        $feed['busy'] = array_values(array_filter($feed['busy'], fn ($b) => ! isset($b['date']) || str_starts_with($b['date'], $month.'-')));
        $minutes = max(15, min(180, (int) ($product['minutes'] ?? 60)));
        $mode = $product['online_enabled'] ? 'online' : 'inperson';
        $windows = array_values(array_filter($feed['weekly'], fn ($w) => in_array($w['mode'], [$mode, 'both'], true)));
        $included = array_map(fn ($s) => mb_strtolower(trim($s)), $product['subjects'] ?? []);
        if ($included) {
            foreach ($windows as &$window) {
                $window['subjects'] = array_values(array_filter($window['subjects'], fn ($s) => in_array(mb_strtolower(trim($s)), $included, true)));
            }unset($window);
            $windows = array_values(array_filter($windows, fn ($w) => count($w['subjects']) > 0));
        }$subjects = [];
        $teacherIds = [];
        foreach ($windows as $w) {
            foreach ($w['subjects'] as $topic) {
                $subjects[$topic] = $topic;
            }if (! $subject || in_array($subject, $w['subjects'], true)) {
                $teacherIds[$w['teacher_id']] = true;
            }
        }$teachers = collect($feed['teachers'])->filter(fn ($t) => isset($teacherIds[$t['id']]))->map(fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name']])->keyBy('id');
        $subjects = array_values($subjects);
        sort($subjects);
        $days = [];
        $expiry = ! empty($product['days']) ? now('Europe/Madrid')->startOfDay()->addDays($product['days'] - 1) : null;
        for ($date = $start->copy(); $date->month === $start->month; $date->addDay()) {
            if ($date->lt(now('Europe/Madrid')->startOfDay()) || ($expiry && $date->gt($expiry))) {
                continue;
            }$weekday = $date->dayOfWeekIso - 1;
            $free = [];
            foreach ($windows as $w) {
                if ($w['weekday'] !== $weekday || ($teacher && $w['teacher_id'] !== $teacher) || ! $teachers->has($w['teacher_id']) || ($subject && ! in_array($subject, $w['subjects'], true))) {
                    continue;
                }$from = self::minute($w['start']);
                $to = self::minute($w['end']);
                if ($date->isSameDay(now('Europe/Madrid'))) {
                    $now = now('Europe/Madrid');
                    $from = max($from, (int) (ceil(($now->hour * 60 + $now->minute) / 15) * 15));
                }$parts = [[$from, $to]];
                foreach ($feed['busy'] as $busy) {
                    if ($busy['teacher_id'] !== $w['teacher_id'] || isset($busy['date']) && $busy['date'] !== $date->toDateString() || isset($busy['weekday']) && $busy['weekday'] !== $weekday) {
                        continue;
                    }$a = self::minute($busy['start']);
                    $b = self::minute($busy['end']);
                    $next = [];
                    foreach ($parts as [$left,$right]) {
                        if ($b <= $left || $a >= $right) {
                            $next[] = [$left, $right];
                        } else {
                            if ($a > $left) {
                                $next[] = [$left, min($a, $right)];
                            }if ($b < $right) {
                                $next[] = [max($b, $left), $right];
                            }
                        }
                    }$parts = $next;
                }
                foreach ($parts as [$left,$right]) {
                    if ($right - $left >= $minutes) {
                        $key = $w['teacher_id'].':'.$left.':'.$right;
                        $free[$key] = ['teacher_id' => $w['teacher_id'], 'teacher_name' => $teachers->get($w['teacher_id'])['name'], 'start' => self::clock($left), 'end' => self::clock($right), 'subjects' => $w['subjects']];
                    }
                }
            }
            if ($free) {
                $free = array_values($free);
                usort($free, fn ($a, $b) => [$a['start'], $a['teacher_name']] <=> [$b['start'], $b['teacher_name']]);
                $days[] = ['date' => $date->toDateString(), 'label' => $date->locale(app()->getLocale())->translatedFormat('l j F'), 'windows' => $free];
            }
        }

        return ['month' => $month, 'timezone' => 'Europe/Madrid', 'minutes' => $minutes, 'subjects' => $subjects, 'teachers' => $teachers->values()->all(), 'days' => $days, 'revision' => $source['revision'] ?? null, 'updated_at' => $source['updated_at'] ?? null];
    }

    private static function minute(string $time): int
    {
        [$h,$m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
