<?php

use App\Http\Middleware\EnsureLoopbackHost;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(EnsureLoopbackHost::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel's default (render JSON whenever the request expects it) is
        // what the fetch() endpoints rely on: lib/http.js sends Accept JSON,
        // and a validation failure must come back as a 422, not a redirect to
        // the HTML page. Inertia visits don't expect JSON, so they still get
        // the redirect-with-errors they need.
    })->create();
