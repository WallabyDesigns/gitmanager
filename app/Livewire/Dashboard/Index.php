<?php

namespace App\Livewire\Dashboard;

use App\Models\Deployment;
use App\Services\DeploymentService;
use App\Services\DockerService;
use App\Services\EditionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public string $tab = 'projects';

    protected $queryString = [
        'tab' => ['except' => 'projects'],
    ];

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function refreshHealth(DeploymentService $service): void
    {
        $projects = Auth::user()->projects()->withHealthMonitoring()->get();

        foreach ($projects as $project) {
            if (! $project->health_checked_at || $project->health_checked_at->lt(now()->subMinute())) {
                $service->checkHealth($project);
            }
        }
    }

    public function render()
    {
        $user = Auth::user();

        $allProjects = $user->projects()
            ->withCount([
                'auditIssues as audit_open_count' => fn ($q) => $q->where('status', 'open'),
            ])
            ->get();

        $monitoredProjects = $allProjects->filter(fn ($p) => $p->hasHealthMonitoring())->values();
        $healthyCount = $monitoredProjects->where('health_status', 'ok')->count();
        $healthIssues = $monitoredProjects->where('health_status', 'na')->values();
        $updatesAvailable = $allProjects->where('updates_available', true)->values();
        $vulnerableProjects = $allProjects->filter(fn ($p) => ($p->audit_open_count ?? 0) > 0)->values();

        $projectIds = $allProjects->pluck('id')->all();

        $recentDeployments = $projectIds === [] ? collect() : Deployment::query()
            ->whereIn('project_id', $projectIds)
            ->where('action', 'deploy')
            ->latest('started_at')
            ->limit(10)
            ->with(['project', 'triggeredBy'])
            ->get();

        $healthHistory = [];
        foreach ($monitoredProjects as $project) {
            $healthHistory[$project->id] = $projectIds === [] ? collect() : Deployment::query()
                ->where('project_id', $project->id)
                ->where('action', 'health_check')
                ->latest('started_at')
                ->limit(30)
                ->get(['status', 'started_at'])
                ->reverse()
                ->values();
        }

        $deploymentsToday = $projectIds === [] ? 0 : Deployment::query()
            ->whereIn('project_id', $projectIds)
            ->where('action', 'deploy')
            ->where('started_at', '>=', now()->startOfDay())
            ->count();

        $infra = $this->loadInfrastructure();

        return view('livewire.dashboard.index', [
            'totalProjects' => $allProjects->count(),
            'monitoredProjects' => $monitoredProjects,
            'healthyCount' => $healthyCount,
            'healthIssues' => $healthIssues,
            'updatesAvailable' => $updatesAvailable,
            'vulnerableProjects' => $vulnerableProjects,
            'recentDeployments' => $recentDeployments,
            'healthHistory' => $healthHistory,
            'deploymentsToday' => $deploymentsToday,
            'infra' => $infra,
        ])->layout('layouts.app', [
            'title' => 'Dashboard',
        ]);
    }

    /**
     * @return array{
     *   available: bool,
     *   containers: array{running: int, stopped: int, total: int},
     *   images: int,
     *   volumes: int,
     *   networks: int,
     *   swarm: array{active: bool, nodes: int, ready_nodes: int, services: int}|null,
     *   is_enterprise: bool,
     *   containers_list: array<int, array<string, mixed>>,
     * }
     */
    private function loadInfrastructure(): array
    {
        $isEnterprise = app(EditionService::class)->current() === EditionService::ENTERPRISE;

        $base = [
            'available' => false,
            'containers' => ['running' => 0, 'stopped' => 0, 'total' => 0],
            'images' => 0,
            'volumes' => 0,
            'networks' => 0,
            'swarm' => null,
            'is_enterprise' => $isEnterprise,
            'containers_list' => [],
        ];

        try {
            $docker = app(DockerService::class);

            if (! $docker->isAvailable()) {
                return $base;
            }

            $base['available'] = true;
            $containers = $docker->listContainers(true);
            $containerCollection = collect($containers);
            $base['containers'] = [
                'running' => $containerCollection->where('State', 'running')->count(),
                'stopped' => $containerCollection->whereNotIn('State', ['running'])->count(),
                'total' => $containerCollection->count(),
            ];
            $base['containers_list'] = $containerCollection->sortBy('Names')->values()->all();
            $base['images'] = count($docker->listImages());
            $base['volumes'] = count($docker->listVolumes());
            $base['networks'] = count($docker->listNetworks());

            if ($isEnterprise) {
                $swarmInfo = $docker->getSwarmInfo();
                $swarmActive = ($swarmInfo['active'] ?? false) === true;

                if ($swarmActive) {
                    $nodes = $docker->listSwarmNodes();
                    $nodeCollection = collect($nodes);
                    $base['swarm'] = [
                        'active' => true,
                        'nodes' => $nodeCollection->count(),
                        'ready_nodes' => $nodeCollection->where('Status', 'Ready')->count(),
                        'services' => count($docker->listSwarmServices()),
                    ];
                } else {
                    $base['swarm'] = ['active' => false, 'nodes' => 0, 'ready_nodes' => 0, 'services' => 0];
                }
            }
        } catch (\Throwable) {
            // Docker unavailable or command failed — return what we have
        }

        return $base;
    }
}
