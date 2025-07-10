<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Auth;
use App\Models\Task;

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
        // Force HTTPS in production
        if (env('APP_ENV') === 'production') {
            URL::forceScheme('https');
        }

        // Make per-user pending task count available in all views
        View::composer('*', function ($view) {
            $user = Auth::user();
            $pendingTaskCount = 0;

            if ($user) {
                $pendingTaskCount = Task::where('status', 'pending')
                    ->where('user_id', $user->id) // ✅ Use correct column
                    ->count();
            }

            $view->with('pendingTaskCount', $pendingTaskCount);
        });
    }
}
