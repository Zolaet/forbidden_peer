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
        $this->configureRateLimiting();
    }

    /**
     * Named limiters for the routes that need one (see routes/api.php).
     *
     * This platform holds user funds, so the login form is a credential-stuffing
     * target and the withdrawal endpoint is a drain target. Neither was
     * throttled: an unauthenticated caller could guess passwords as fast as the
     * network allowed.
     */
    protected function configureRateLimiting(): void
    {
        // Blanket ceiling for every API route, keyed on the token's user when
        // there is one so a shared NAT doesn't throttle unrelated merchants.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Credentials. Keyed on the submitted email *and* the source IP, so
        // neither a single attacker spraying many accounts nor a botnet
        // hammering one account gets more than a trickle.
        RateLimiter::for('auth', function (Request $request) {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(5)->by($email . '|' . $request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Money leaving the platform. Generous enough for retries, far too slow
        // for anyone probing for a race in the withdrawal path.
        RateLimiter::for('withdrawals', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
