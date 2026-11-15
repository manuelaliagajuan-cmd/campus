<?php

namespace App\Services;

use App\Models\Course;

class StoreCatalog
{
    public static function all(): array
    {
        $items = [];
        $source = ErpConnection::snapshot();
        foreach ($source['pass_types'] ?? [] as $p) {
            if (is_int($p['price'] ?? null) && $p['price'] >= 50 && $p['price'] <= 9999999 && is_int($p['sessions'] ?? null) && $p['sessions'] > 0 && $p['sessions'] <= 500 && $p['days'] >= 0 && $p['days'] <= 3650) {
                $items['pass:'.$p['id']] = ['key' => 'pass:'.$p['id'], 'kind' => 'pass', 'name' => $p['name'], 'amount' => $p['price'], 'source_id' => $p['id'], 'sessions' => $p['sessions'], 'days' => $p['days'], 'minutes' => (int) ($p['minutes'] ?? 60), 'subjects' => $p['subjects'] ?? [], 'online_enabled' => $p['online_enabled'], 'color' => $p['color'] ?? '#212a5e'];
            }
        }foreach (Course::where('published', true)->where('store_listed', true)->whereNull('erp_id')->where('store_price_cents', '>=', 50)->get() as $c) {
            $items['course:'.$c->id] = ['key' => 'course:'.$c->id, 'kind' => 'course', 'name' => $c->title, 'amount' => $c->store_price_cents, 'course_id' => $c->id, 'description' => $c->description];
        }

        return $items;
    }

    public static function enabled(): bool
    {
        return str_starts_with(config('payments.stripe_key') ?? '', 'sk_live_') && ! empty(config('payments.stripe_webhook')) && is_file(config('payments.erp_core') ?? '');
    }
}
