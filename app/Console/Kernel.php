<?php

namespace App\Console;

use App\Console\Commands\PulseCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\ProcessFeeds::class,
        \App\Console\Commands\PulseCheck::class,

    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('pulse:check')->everyFifteenMinutes();
        $schedule->command('health:check')->everyFifteenMinutes();
        // $schedule->command('response:check')->everyThirtyMinutes();

        $schedule->call(function () {
            \Artisan::call('horizon:snapshot');
        })->everyFifteenMinutes();

        $schedule->command('health:mail-log --days=7')
            ->weeklyOn(1, '07:00')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
