<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
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
    public function handle(DeploymentService $service, DeploymentQueueService $queue): int
    {
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
                        $service->checkHealth($project, false, true);
                    }
                } else {
                    $service->checkHealth($project, false, true);
                    $this->line("No updates for {$project->name}.");
                }
            } catch (\Throwable $exception) {
                $this->error("Failed for {$project->name}: {$exception->getMessage()}");
            }
        }

        $service->flushHealthNotifications();

        return self::SUCCESS;
    }
}
