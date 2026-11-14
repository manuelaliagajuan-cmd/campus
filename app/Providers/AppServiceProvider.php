<?php

namespace App\Providers;

use App\Models\Announcement;
use App\Models\Course;
use App\Services\CourseNavigation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['start' => 10, 'join' => 20, 'poll' => 120, 'event' => 300, 'rate' => 10] as $action => $limit) {
            RateLimiter::for('online-'.$action, fn (Request $r) => Limit::perMinute($limit)->by('online-'.$action.':'.$r->user()->id));
        }
        View::composer('layout', function ($view) {
            $unread = 0;
            $courseNavigation = null;
            $accessibleCourseCount = 0;
            if ($user = auth()->user()) {
                try {
                    $unread = Announcement::visibleTo($user)->unreadBy($user)->count();
                    $accessibleCourseCount = Course::accessibleTo($user)->count();
                    $courseNavigation = CourseNavigation::fromRequest(request(), $user);
                } catch (\Throwable $e) {
                    $unread = 0;
                }
            }
            $view->with('unreadNotifications', $unread)->with('courseNavigation', $courseNavigation)->with('accessibleCourseCount', $accessibleCourseCount);
        });
    }
}
