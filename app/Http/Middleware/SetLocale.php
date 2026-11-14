<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $r, Closure $next)
    {
        $locale = $r->user()?->locale ?? $r->session()->get('locale', 'es');
        app()->setLocale(in_array($locale, ['es', 'en'], true) ? $locale : 'es');

        return $next($r);
    }
}
