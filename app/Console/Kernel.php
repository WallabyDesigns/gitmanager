<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('sitemap:generate')->daily();
        $schedule->command('projects:auto-deploy')->everyFiveMinutes()->withoutOverlapping();
        if (config('gitmanager.deploy_queue.enabled', true)) {
            $schedule->command('deployments:process-queue')->everyMinute()->withoutOverlapping();
        }
        $schedule->command('security:sync')->hourly()->withoutOverlapping();
        $schedule->command('dependabot:auto-merge')->hourly()->withoutOverlapping();
        if (config('gitmanager.self_update.enabled')) {
            $schedule->command('gitmanager:self-update')->dailyAt('02:30')->withoutOverlapping();
        }
        // $schedule->command('site:publish')->hourly();
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
