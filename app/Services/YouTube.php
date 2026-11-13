<?php

namespace App\Services;

class YouTube
{
    public static function id(?string $url): ?string
    {
        if (! $url) {
            return null;
        }$u = parse_url($url);
        if (! $u || ! in_array(strtolower($u['host'] ?? ''), ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'], true)) {
            return null;
        }
        $host = strtolower($u['host']);
        $path = trim($u['path'] ?? '', '/');
        parse_str($u['query'] ?? '', $query);
        $id = $host === 'youtu.be' ? $path : ($query['v'] ?? (preg_match('~^(?:embed|shorts)/([A-Za-z0-9_-]{11})$~', $path, $m) ? $m[1] : ''));

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }
}
