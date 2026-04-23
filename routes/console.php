<?php

use App\Services\LogCleanupService;
use App\Services\SettingsService;
use App\Support\SchedulerTaskIntervals;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$schedulerTaskIntervals = SchedulerTaskIntervals::defaults();
try {
    $schedulerTaskIntervals = SchedulerTaskIntervals::normalize(
        app(SettingsService::class)->get(SchedulerTaskIntervals::SETTINGS_KEY, [])
    );
} catch (\Throwable $exception) {
    // Ignore settings lookup failures during early installs.
}

$schedulerTaskDefinitions = SchedulerTaskIntervals::definitions();
$schedulerTaskCron = static function (string $task) use ($schedulerTaskIntervals, $schedulerTaskDefinitions): string {
    $definition = $schedulerTaskDefinitions[$task] ?? [
        'default_value' => 1,
        'default_unit' => 'minutes',
        'anchor' => '00:00',
    ];

    $interval = $schedulerTaskIntervals[$task] ?? [
        'value' => (int) $definition['default_value'],
        'unit' => (string) $definition['default_unit'],
    ];

    return SchedulerTaskIntervals::cronExpression($interval, (string) ($definition['anchor'] ?? '00:00'));
};

Schedule::command('scheduler:heartbeat')->everyMinute()->withoutOverlapping();
Schedule::command('license:verify')->everyTenMinutes()->withoutOverlapping();
Schedule::command('sitemap:generate')->daily();
Schedule::command('app:self-audit')->cron($schedulerTaskCron('self_audit'))->withoutOverlapping();
Schedule::command('projects:auto-deploy')->cron($schedulerTaskCron('project_health_checks'))->withoutOverlapping();

if (config('gitmanager.deploy_queue.enabled', true)) {
    Schedule::command('deployments:process-queue')->cron($schedulerTaskCron('queue_processing'))->withoutOverlapping();
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
    Schedule::command('gitmanager:self-update')->cron($schedulerTaskCron('self_update'))->withoutOverlapping();
}

$logCleanupEnabled = false;
$logRetentionDays = LogCleanupService::DEFAULT_RETENTION_DAYS;
try {
    $logCleanupEnabled = (bool) app(SettingsService::class)->get('system.logs.cleanup_enabled', false);
    $logRetentionDays = LogCleanupService::normalizeRetentionDays(
        app(SettingsService::class)->get('system.logs.retention_days', LogCleanupService::DEFAULT_RETENTION_DAYS)
    );
} catch (\Throwable $exception) {
    // Ignore settings lookup failures during early installs.
}

if ($logCleanupEnabled) {
    Schedule::command('logs:cleanup', ['--days' => $logRetentionDays])->dailyAt('03:45')->withoutOverlapping();
}
