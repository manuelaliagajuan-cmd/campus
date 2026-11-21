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
        // Mutation endpoints publish explicitly; live polls must not rebuild a global directory.
        if (! $request->routeIs('online.poll', 'online.event', 'online.notes', 'online.connectivity', 'online.schedule-state', 'notifications.count', 'staff.schedule.data', 'passes.schedule')) {
            CampusAccessRegistry::safelyPublish($request->user());
        }
        if ($course = $request->route('course')) {
            if ($course instanceof Course) {
                $course->refresh();
            }
        }

        return $next($request);
    }
}
