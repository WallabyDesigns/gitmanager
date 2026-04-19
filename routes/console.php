<?php

use App\Services\SettingsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('scheduler:heartbeat')->everyMinute()->withoutOverlapping();
Schedule::command('license:verify')->everyTenMinutes()->withoutOverlapping();
Schedule::command('sitemap:generate')->daily();
Schedule::command('projects:auto-deploy')->everyFiveMinutes()->withoutOverlapping();

if (config('gitmanager.deploy_queue.enabled', true)) {
    Schedule::command('deployments:process-queue')->everyMinute()->withoutOverlapping();
}

Schedule::command('security:sync')->hourly()->withoutOverlapping();
Schedule::command('dependabot:auto-merge')->hourly()->withoutOverlapping();
Schedule::command('workspaces:clean')->weekly()->withoutOverlapping();

$autoUpdates = (bool) config('gitmanager.self_update.enabled');
try {
    $autoUpdates = $autoUpdates
        && (bool) app(SettingsService::class)->get('system.auto_update', $autoUpdates);
} catch (\Throwable $exception) {
    // Ignore settings lookup failures during early installs.
}

if ($autoUpdates) {
    Schedule::command('gitmanager:self-update')->dailyAt('02:30')->withoutOverlapping();
}
