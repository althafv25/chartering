<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->throttleApi('api');
        $middleware->api(append: [ApiSecurityHeaders::class]);
        // Stateless API: never redirect unauthenticated requests to a web login route.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('contracts:check-expiry')->dailyAt('06:00')->withoutOverlapping();
        $schedule->command('ais:ingest')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('ais:check-stale')->hourly()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ApiExceptionRenderer);
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
