<?php

namespace App\Livewire\Projects;

use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Services\DeploymentService;
use Illuminate\Support\Facades\Auth;
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

    public function refreshHealth(DeploymentService $service): void
    {
        $projects = Auth::user()
            ->projects()
            ->get();

        foreach ($projects as $project) {
            if (! $project->health_checked_at || $project->health_checked_at->lt(now()->subMinute())) {
                $service->checkHealth($project);
            }
        }
    }

    public function render()
    {
        $baseQuery = Auth::user()
            ->projects()
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
                    ->orWhere('repo_url', 'like', $like);
            });
        }

        if ($this->filter === 'health') {
            $baseQuery->where(function ($query) {
                $query->whereNull('health_status')
                    ->orWhere('health_status', '!=', 'ok');
            });
        } elseif ($this->filter === 'permissions') {
            $baseQuery->where('permissions_locked', true);
        }

        $projects = (clone $baseQuery)
            ->latest()
            ->get();

        $projectIds = $projects->pluck('id')->all();
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
            'counts' => [
                'all' => Auth::user()->projects()->count(),
                'health' => Auth::user()
                    ->projects()
                    ->where(function ($query) {
                        $query->whereNull('health_status')
                            ->orWhere('health_status', '!=', 'ok');
                    })
                    ->count(),
                'permissions' => Auth::user()->projects()->where('permissions_locked', true)->count(),
            ],
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.index-header'),
        ]);
    }

}
