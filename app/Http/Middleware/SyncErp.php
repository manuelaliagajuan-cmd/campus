<?php

namespace App\Http\Middleware;

use App\Models\Course;
use App\Services\CampusAccessRegistry;
use App\Services\ErpConnection;
use Closure;
use Illuminate\Http\Request;

class SyncErp
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('erp', ErpConnection::sync($request->user()));
        CampusAccessRegistry::safelyPublish($request->user());
        if ($course = $request->route('course')) {
            if ($course instanceof Course) {
                $course->refresh();
            }
        }

        return $next($request);
    }
}
