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
        $configured = (int) config('gitmanager.deployments.job_timeout', 0);
        if ($configured > 0) {
            $this->timeout = $configured;

            return;
        }

        $processTimeout = (int) config('gitmanager.deployments.process_timeout', config('gitmanager.process_timeout', 900));
        $this->timeout = $processTimeout > 0 ? $processTimeout + 300 : 0;
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
