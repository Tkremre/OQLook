<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Http\Middleware\HandleInertiaRequests;
use App\Console\Commands\OQLikeDiscoverCommand;
use App\Console\Commands\OQLikeScanCommand;
use App\Console\Commands\OQLikeWatchdogCommand;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        OQLikeDiscoverCommand::class,
        OQLikeScanCommand::class,
        OQLikeWatchdogCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        if ((bool) config('oqlike.watchdog_enabled', true)) {
            $schedule
                ->command('oqlike:watchdog')
                ->everyFiveMinutes()
                ->withoutOverlapping();
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
