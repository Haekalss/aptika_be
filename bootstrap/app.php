<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // HTTP kernel configuration runs before the environment is loaded.
        app()->booted(function (Application $app) use ($middleware): void {
            if ($app->isProduction()) {
                // Wasmer terminates TLS before forwarding requests to PHP.
                $middleware->trustProxies(at: '*', headers:
                    Request::HEADER_X_FORWARDED_FOR |
                    Request::HEADER_X_FORWARDED_PORT |
                    Request::HEADER_X_FORWARDED_PROTO
                );
            }
        });

        $middleware->alias([
            'active'       => \App\Http\Middleware\CheckActiveUser::class,
            'role'         => \App\Http\Middleware\CheckRole::class,
            'admin.aptika' => \App\Http\Middleware\EnsureAdminAptika::class,
            'service.enabled' => \App\Http\Middleware\EnsureServiceEnabled::class,
            'super_admin'  => \App\Http\Middleware\CheckSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, \Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }
            return $request->expectsJson();
        });
    })->create();
