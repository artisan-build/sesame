<?php

use App\Sesame\Http\Housekeeping;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], base_path('routes/sesame.php'));
            Route::group([], base_path('routes/mcp.php'));
        },
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sesame has no scheduler: any inbound request drains this instance's
        // ingest buffer and does the periodic repair after its response.
        $middleware->append(Housekeeping::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
