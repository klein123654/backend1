<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
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
        RateLimiter::for('ai-search', function (Request $request): Limit {
            $userId = $request->user('sanctum')?->getAuthIdentifier();
            $key = $userId !== null ? 'user:'.$userId : 'ip:'.$request->ip();

            return Limit::perMinute(10)->by($key);
        });

        ResetPassword::createUrlUsing(function (object $user, string $token): string {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password?token='.urlencode($token)
                .'&email='.urlencode($user->getEmailForPasswordReset());
        });
    }
}
