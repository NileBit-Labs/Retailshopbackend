<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // A person asking the AI questions in a burst is fine; a script hammering it is not.
        RateLimiter::for('ask', fn (Request $request) => Limit::perMinute(12)->by($request->user()?->id ?: $request->ip()));
    }
}
