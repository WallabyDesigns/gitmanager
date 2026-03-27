<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('projects:auto-deploy')->everyFiveMinutes();
Schedule::command('security:sync')->hourly();
Schedule::command('dependabot:auto-merge')->hourly();
Schedule::command('workspaces:clean')->weekly();
