<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

class StoreOrigin
{
    public static function token(string $host): string
    {
        return Crypt::encryptString(json_encode(['host' => $host, 'expires' => now()->addDay()->timestamp]));
    }

    public static function host(?string $token): string
    {
        if (! $token) {
            return 'campus.cecalicante.es';
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            abort(422, 'El enlace de compra no es válido. Vuelve a abrir el catálogo.');
        }
        abort_unless(in_array($data['host'] ?? '', ['cecalicante.es', 'campus.cecalicante.es'], true) && ($data['expires'] ?? 0) >= now()->timestamp, 422, 'El enlace de compra ha caducado. Vuelve a abrir el catálogo.');

        return $data['host'];
    }
}
