<?php

use App\Http\Middleware\ControlarAcceso;
use App\Http\Middleware\ExigirCambioContrasena;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ValidarSesion;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['sidebar_state']);

        // Origen y escritura antes que cualquier otro middleware web.
        $middleware->web(prepend: [ControlarAcceso::class], append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Sesión revocada o cerrada: termina antes de cualquier otra regla.
            ValidarSesion::class,
            ExigirCambioContrasena::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
