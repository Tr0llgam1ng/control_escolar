<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'service.key' => \App\Http\Middleware\VerifyServiceKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every response of a service is JSON (it is consumed by the gateway, never by a browser).
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
