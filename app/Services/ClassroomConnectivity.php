<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ClassroomConnectivity
{
    private const FILE = 'rtc/cloudflare.enc';

    public static function configured(): bool
    {
        return Storage::disk('local')->exists(self::FILE) || self::hasRelay(config('online.ice_servers', []));
    }

    public static function hasRelay(array $servers): bool
    {
        foreach ($servers as $s) {
            foreach ((array) ($s['urls'] ?? []) as $url) {
                if (is_string($url) && preg_match('/^turns?:/i', $url) && ! empty($s['username']) && ! empty($s['credential'])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function generate(string $key, string $token): array
    {
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(4)->timeout(8)->withOptions(['allow_redirects' => false])->post('https://rtc.live.cloudflare.com/v1/turn/keys/'.$key.'/credentials/generate-ice-servers', ['ttl' => 86400]);
            $servers = $response->successful() ? $response->json('iceServers') : null;
            if (! is_array($servers) || ! self::hasRelay($servers)) {
                throw new RuntimeException;
            }

            return $servers;
        } catch (\Throwable $e) {
            // Never put the provider response or master credential in logs/errors.
            throw new RuntimeException(__('No se pudo verificar el servicio de conexión. Comprueba las claves de Cloudflare e inténtalo de nuevo.'));
        }
    }

    public static function configure(string $key, string $token): void
    {
        self::generate($key, $token);
        $disk = Storage::disk('local');
        $disk->makeDirectory('rtc');
        File::replace($disk->path(self::FILE), Crypt::encryptString(json_encode(['key' => $key, 'token' => $token], JSON_THROW_ON_ERROR)), 0600);
    }

    public static function servers(string $principal): array
    {
        $fallback = config('online.ice_servers', []);
        if (! Storage::disk('local')->exists(self::FILE)) {
            return $fallback;
        }
        try {
            $saved = json_decode(Crypt::decryptString(Storage::disk('local')->get(self::FILE)), true, flags: JSON_THROW_ON_ERROR);

            $cacheKey = 'rtc-ice:'.hash('sha256', $principal.'|'.$saved['key'].'|'.$saved['token']);
            $cached = Cache::get($cacheKey);
            if ($cached && $cached['refresh_at'] > now()->timestamp) {
                return $cached['servers'];
            }
            try {
                $servers = self::generate($saved['key'], $saved['token']);
                Cache::put($cacheKey, ['servers' => $servers, 'refresh_at' => now()->addMinutes(10)->timestamp, 'expires_at' => now()->addDay()->timestamp], now()->addHours(23));

                return $servers;
            } catch (\Throwable $e) {
                if ($cached && $cached['expires_at'] > now()->addMinutes(2)->timestamp) {
                    return $cached['servers'];
                }

                return $fallback;
            }
        } catch (\Throwable $e) {
            return $fallback;
        }
    }
}
