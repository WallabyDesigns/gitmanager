<?php

namespace App\Livewire\Projects;

use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Services\DeploymentService;
use App\Services\DeploymentQueueService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Livewire\Component;

class Index extends Component
{
    public string $projectsTab = 'list';
    public string $search = '';
    public string $filter = 'all';

    protected $queryString = [
        'search' => ['except' => ''],
        'filter' => ['except' => 'all'],
    ];

    public function checkAllHealth(DeploymentService $service): void
    {
        $projects = Auth::user()
            ->projects()
            ->get();

        $this->runHealthChecks($service, $projects, true);

        $this->dispatch('notify', message: 'Health checks complete.');
    }

    public function checkAllUpdates(DeploymentService $service): void
    {
        $projects = Auth::user()
            ->projects()
            ->get();

        $this->runUpdateChecks($service, $projects, false);

        $this->dispatch('notify', message: 'Update checks complete.');
    }

    public function refreshHealth(DeploymentService $service): void
    {
        $projects = Auth::user()
            ->projects()
            ->get();

        $this->runHealthChecks($service, $projects);
        $this->runUpdateChecks($service, $projects, true);
    }

    public function render()
    {
        app(DeploymentService::class)->releaseStaleRunningDeployments();
        app(DeploymentQueueService::class)->releaseStaleRunning();

        $baseQuery = Auth::user()
            ->projects()
            ->with('ftpAccount')
            ->addSelect([
                'last_successful_deploy_at' => Deployment::query()
                    ->select('started_at')
                    ->whereColumn('project_id', 'projects.id')
                    ->where('action', 'deploy')
                    ->where('status', 'success')
                    ->latest('started_at')
                    ->limit(1),
            ]);

        $search = trim($this->search);
        if ($search !== '') {
            $baseQuery->where(function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where('name', 'like', $like)
                    ->orWhere('local_path', 'like', $like)
                    ->orWhere('repo_url', 'like', $like)
                    ->orWhere('site_url', 'like', $like);
            });
        }

        if ($this->filter === 'health') {
            $baseQuery->where(function ($query) {
                $query->whereNull('health_status')
                    ->orWhere('health_status', '!=', 'ok');
            });
        } elseif ($this->filter === 'permissions') {
            $baseQuery->where('permissions_locked', true)
                ->where('ftp_enabled', false)
                ->where('ssh_enabled', false);
        }

        $projects = (clone $baseQuery)
            ->latest()
            ->get();

        $projectIds = $projects->pluck('id')->all();
        $queueProjectIds = $projectIds === []
            ? []
            : DeploymentQueueItem::query()
                ->whereIn('project_id', $projectIds)
                ->whereIn('status', ['queued', 'running'])
                ->pluck('project_id')
                ->all();
        $runningDeployments = $projectIds === []
            ? []
            : Deployment::query()
                ->whereIn('project_id', $projectIds)
                ->where('status', 'running')
                ->pluck('project_id')
                ->all();

        $runningQueueItems = $projectIds === []
            ? []
            : DeploymentQueueItem::query()
                ->whereIn('project_id', $projectIds)
                ->where('status', 'running')
                ->pluck('project_id')
                ->all();

        $buildInProcess = array_values(array_unique(array_merge($runningDeployments, $runningQueueItems)));

        return view('livewire.projects.index', [
            'projects' => $projects,
            'buildInProcess' => $buildInProcess,
            'queueProjects' => $queueProjectIds,
            'counts' => [
                'all' => Auth::user()->projects()->count(),
                'health' => Auth::user()
                    ->projects()
                    ->where(function ($query) {
                        $query->whereNull('health_status')
                            ->orWhere('health_status', '!=', 'ok');
                    })
                    ->count(),
                'permissions' => Auth::user()
                    ->projects()
                    ->where('permissions_locked', true)
                    ->where('ftp_enabled', false)
                    ->where('ssh_enabled', false)
                    ->count(),
            ],
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.index-header'),
            'title' => 'Projects',
        ]);
    }

    private function queueEnabled(): bool
    {
        return (bool) config('gitmanager.deploy_queue.enabled', true);
    }

    private function shouldAutoCheckHealth(\App\Models\Project $project): bool
    {
        return (bool) (
            $project->health_url
            || $project->site_url
            || $project->last_deployed_at
            || $project->last_deployed_hash
        );
    }

    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\Project> $projects
     */
    private function runHealthChecks(DeploymentService $service, $projects, bool $force = false): void
    {
        foreach ($projects as $project) {
            if ($force) {
                $service->checkHealth($project);
                continue;
            }

            if (
                $this->shouldAutoCheckHealth($project)
                && (! $project->health_checked_at || $project->health_checked_at->lt(now()->subMinute()))
            ) {
                $service->checkHealth($project);
            }
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\Project> $projects
     */
    private function runUpdateChecks(DeploymentService $service, $projects, bool $autoNotify): void
    {
        foreach ($projects as $project) {
            $updatesCheckedAt = $project->updates_checked_at;
            if (is_string($updatesCheckedAt)) {
                $updatesCheckedAt = Carbon::parse($updatesCheckedAt);
            }

            if (! $updatesCheckedAt || $updatesCheckedAt->lt(now()->subMinutes(5))) {
                $service->checkHealth($project, false, $autoNotify);
                $wasAvailable = (bool) $project->updates_available;
                try {
                    $hasUpdates = $service->checkForUpdates($project);
                } catch (\Throwable $exception) {
                    $this->markUpdateCheckAttempt($project);
                    continue;
                }

                if (! $wasAvailable && $hasUpdates && $project->auto_deploy && $this->queueEnabled()) {
                    app(DeploymentQueueService::class)->enqueue($project, 'deploy', ['reason' => 'auto_update'], Auth::user());
                }
            }
        }
    }

    private function markUpdateCheckAttempt(\App\Models\Project $project): void
    {
        $project->last_checked_at = now();
        $project->updates_checked_at = now();
        $project->save();
    }

}
