<?php

namespace App\Providers;

use App\Models\Announcement;
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
        View::composer('layout', function ($view) {
            $unread = 0;
            if ($user = auth()->user()) {
                try {
                    $unread = Announcement::visibleTo($user)->unreadBy($user)->count();
                } catch (\Throwable $e) {
                    $unread = 0;
                }
            }
            $view->with('unreadNotifications', $unread);
        });
    }
}
