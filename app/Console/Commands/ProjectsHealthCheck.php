<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\DeploymentService;
use App\Services\SchedulerService;
use Illuminate\Console\Command;

class ProjectsHealthCheck extends Command
{
    protected $signature = 'projects:health-check';

    protected $description = 'Run health checks for all monitored projects.';

    public function handle(DeploymentService $service, SchedulerService $scheduler): int
    {
        $scheduler->recordHeartbeat('schedule');

        $checked = 0;
        $failed = 0;

        Project::query()
            ->withHealthMonitoring()
            ->chunkById(50, function ($projects) use ($service, &$checked, &$failed): void {
                foreach ($projects as $project) {
                    try {
                        $service->checkHealth($project, true, true);
                        $checked++;
                    } catch (\Throwable $exception) {
                        $failed++;
                        $this->error("Health check failed for {$project->name}: {$exception->getMessage()}");
                    }
                }
            });

        $service->flushHealthNotifications();

        $this->info("Checked {$checked} monitored project(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
