<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use App\Services\AuditService;
use App\Services\SchedulerService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ProjectsAutoDeploy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:auto-deploy';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto deploy projects that have new commits available.';

    /**
     * Execute the console command.
     */
    public function handle(
        DeploymentService $service,
        DeploymentQueueService $queue,
        AuditService $audit,
        SchedulerService $scheduler,
        SettingsService $settings
    ): int
    {
        $scheduler->recordHeartbeat('schedule');
        $auditEnabled = (bool) $settings->get('system.audit_enabled', false);
        $projects = Project::query()
            ->where('auto_deploy', true)
            ->get();

        foreach ($projects as $project) {
            try {
                if ($service->checkForUpdates($project)) {
                    if (config('gitmanager.deploy_queue.enabled', true)) {
                        $queue->enqueue($project, 'deploy');
                        $this->info("Queued deploy for {$project->name}.");
                    } else {
                        $service->deploy($project);
                        $this->info("Deployed {$project->name}.");
                        if ($project->hasSuccessfulDeployment()) {
                            $service->checkHealth($project, false, true);
                        }
                    }
                } else {
                    if ($project->hasSuccessfulDeployment()) {
                        $service->checkHealth($project, false, true);
                    }
                    $this->line("No updates for {$project->name}.");
                }
                if ($auditEnabled) {
                    if (! $this->shouldRunAudit($project)) {
                        $lastRun = $project->last_audit_at
                            ? $project->last_audit_at->format('M j, Y g:i a')
                            : 'never';
                        $this->line("Skipping audit for {$project->name}: last run {$lastRun}.");
                    } elseif ($project->permissions_locked && ! $project->ftp_enabled && ! $project->ssh_enabled) {
                        $this->warn("Skipping audit for {$project->name}: permissions are locked.");
                    } elseif ($this->deploymentInProgress($project)) {
                        $this->warn("Skipping audit for {$project->name}: deployment already running.");
                    } else {
                        try {
                            $audit->auditProject($project, null, true, true);
                            $this->info("Audit completed for {$project->name}.");
                        } catch (\Throwable $exception) {
                            $this->error("Audit failed for {$project->name}: {$exception->getMessage()}");
                        } finally {
                            $this->markAuditAttempt($project);
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $this->error("Failed for {$project->name}: {$exception->getMessage()}");
            }
        }

        $service->flushHealthNotifications();

        return self::SUCCESS;
    }

    private function deploymentInProgress(Project $project): bool
    {
        $projectId = $project->id;

        $runningDeployment = \App\Models\Deployment::query()
            ->where('project_id', $projectId)
            ->where('status', 'running')
            ->exists();

        if ($runningDeployment) {
            return true;
        }

        return \App\Models\DeploymentQueueItem::query()
            ->where('project_id', $projectId)
            ->where('status', 'running')
            ->exists();
    }

    private function shouldRunAudit(Project $project): bool
    {
        $lastAudit = $project->last_audit_at;
        if ($lastAudit instanceof Carbon) {
            return $lastAudit->lt(now()->subHour());
        }

        if (is_string($lastAudit)) {
            return Carbon::parse($lastAudit)->lt(now()->subHour());
        }

        return true;
    }

    private function markAuditAttempt(Project $project): void
    {
        if (! $this->hasAuditTimestampColumn()) {
            return;
        }

        $project->last_audit_at = now();
        $project->save();
    }

    private function hasAuditTimestampColumn(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        try {
            $available = Schema::hasColumn('projects', 'last_audit_at');
        } catch (\Throwable $exception) {
            $available = false;
        }

        return $available;
    }
}
