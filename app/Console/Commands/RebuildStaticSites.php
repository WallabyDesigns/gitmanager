<?php

namespace App\Console\Commands;

use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Services\DeploymentQueueService;
use App\Services\SchedulerService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RebuildStaticSites extends Command
{
    protected $signature = 'projects:rebuild-static';

    protected $description = 'Queue scheduled rebuilds for static/node/react projects that are due.';

    public function handle(DeploymentQueueService $queue, SchedulerService $scheduler): int
    {
        $scheduler->recordHeartbeat('schedule');

        $projects = Project::query()
            ->where('rebuild_enabled', true)
            ->get();

        $queued = 0;
        foreach ($projects as $project) {
            if (! $this->isDue($project)) {
                continue;
            }

            $existing = DeploymentQueueItem::query()
                ->where('project_id', $project->id)
                ->whereIn('status', ['queued', 'running'])
                ->where('action', 'rebuild_static')
                ->exists();

            if ($existing) {
                continue;
            }

            $queue->enqueue($project, 'rebuild_static', ['source' => 'scheduled_rebuild']);
            $queued++;
        }

        $this->info("Queued {$queued} static rebuild(s).");

        return self::SUCCESS;
    }

    private function isDue(Project $project): bool
    {
        if ($project->last_rebuild_at === null) {
            return true;
        }

        $intervalHours = max(1, (int) $project->rebuild_interval_hours);

        return $project->last_rebuild_at->lt(Carbon::now()->subHours($intervalHours));
    }
}
