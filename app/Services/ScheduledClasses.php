<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class ScheduledClasses
{
    public static function forRoom(array $room): array
    {
        $rows = [];
        if (! isset($room['pass'])) {
            return [];
        }foreach ($room['pass']['bookings'] ?? [] as $b) {
            if ($b['completed']) {
                continue;
            }try {
                $start = Carbon::createFromFormat('!Y-m-d H:i', $b['date'].' '.$b['start'], 'Europe/Madrid');
                $end = Carbon::createFromFormat('!Y-m-d H:i', $b['date'].' '.$b['end'], 'Europe/Madrid');
                if (! $start || ! $end || $end <= $start || $start->format('Y-m-d H:i') !== $b['date'].' '.$b['start']) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }if ((! empty($room['pass']['start']) && $b['date'] < $room['pass']['start']) || (! empty($room['pass']['expires']) && $b['date'] > $room['pass']['expires'])) {
                continue;
            }
            $rows[] = ['id' => $b['id'], 'room' => $room, 'start' => $start, 'end' => $end, 'closes' => $end->copy()->addMinutes(15), 'signature' => hash('sha256', json_encode([$room['key'], $b, $room['pass']['teacher_id'] ?? null]))];
        }

        return $rows;
    }

    public static function find(array $room, ?string $id): ?array
    {
        return collect(self::forRoom($room))->firstWhere('id', $id);
    }

    public static function upcoming(array $rooms): array
    {
        $rows = [];
        foreach ($rooms as $room) {
            foreach (self::forRoom($room) as $entry) {
                if ($entry['closes']->gte(now())) {
                    $entry['open'] = now()->lte($entry['closes']);
                    $entry['label'] = self::label($entry);
                    $rows[] = $entry;
                }
            }
        }usort($rows, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $rows;
    }

    public static function label(array $e): string
    {
        $now = now('Europe/Madrid');
        if ($now->gte($e['start'])) {
            return __('Clase en curso');
        }$days = (int) $now->copy()->startOfDay()->diffInDays($e['start']->copy()->startOfDay());
        if ($days === 0) {
            return __('En :minutes min', ['minutes' => max(1, (int) ceil($now->diffInMinutes($e['start'])))]);
        }if ($days === 1) {
            return __('Mañana');
        }

        return __('En :days días', ['days' => $days]);
    }

    public static function heading(?array $e): string
    {
        if (! $e) {
            return __('Tus próximas clases aparecerán aquí');
        }$now = now('Europe/Madrid');
        if ($now->gte($e['start'])) {
            return __('Tu clase está en curso');
        }$days = (int) $now->copy()->startOfDay()->diffInDays($e['start']->copy()->startOfDay());
        if ($days === 0) {
            return __('Tu clase empieza hoy');
        }if ($days === 1) {
            return __('Tu clase empieza mañana');
        }

        return __('Tu clase empieza en :days días', ['days' => $days]);
    }
}
