<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PrivateResponse
{
    public function handle(Request $request, Closure $next)
    {
        $started = hrtime(true);
        $response = $next($request);
        $elapsed = (hrtime(true) - $started) / 1e6;
        $response->headers->set('Server-Timing', 'app;dur='.number_format($elapsed, 1, '.', ''));
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        if ($elapsed > 1500) {
            Log::warning('Slow campus request', ['route' => $request->route()?->getName(), 'method' => $request->method(), 'status' => $response->getStatusCode(), 'duration_ms' => (int) $elapsed]);
        }
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-CEC-Release', basename(base_path()));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
