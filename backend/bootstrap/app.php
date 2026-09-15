<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php', // <--- Ensure this line exists!
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        // The blanket API ceiling. The 'api' limiter itself is defined in
        // AppServiceProvider; routes that need a tighter budget (login,
        // withdrawals) name their own.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Anything under /api answers JSON regardless of Accept. Without this,
        // a firstOrFail() 404 renders an HTML error page to any client that
        // isn't axios — curl, Postman, a mobile build.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();