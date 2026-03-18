<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeployProjectFromWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    public function __construct(public int $projectId)
    {
    }

    public function handle(DeploymentService $service, DeploymentQueueService $queue): void
    {
        $project = Project::find($this->projectId);
        if (! $project || ! $project->auto_deploy) {
            return;
        }

        if (config('gitmanager.deploy_queue.enabled', true)) {
            $queue->enqueue($project, 'deploy');
            return;
        }

        $service->deploy($project);
    }
}
