<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

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
        SettingsService $settings
    ): int
    {
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
                    if ($project->permissions_locked && ! $project->ftp_enabled && ! $project->ssh_enabled) {
                        $this->warn("Skipping audit for {$project->name}: permissions are locked.");
                    } elseif ($this->deploymentInProgress($project)) {
                        $this->warn("Skipping audit for {$project->name}: deployment already running.");
                    } else {
                        $audit->auditProject($project, null, true, true);
                        $this->info("Audit completed for {$project->name}.");
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
}
