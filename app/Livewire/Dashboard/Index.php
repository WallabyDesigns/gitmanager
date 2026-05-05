<?php

namespace App\Livewire\Dashboard;

use App\Models\Deployment;
use App\Models\Project;
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

    public function render()
    {
        $user = Auth::user();

        $allProjects = Project::query()
            ->withCount([
                'auditIssues as audit_open_count' => fn ($q) => $q->where('status', 'open'),
            ])
            ->get();

        $monitoredProjects = $allProjects->filter(fn ($p) => $p->hasHealthMonitoring())->values();
        $healthyCount = $monitoredProjects->where('health_status', 'ok')->count();
        $healthIssues = $monitoredProjects->where('health_status', 'na')->values();
        $updatesAvailable = $user->projects()->where('updates_available', true)->get();
        $vulnerableProjects = $user->projects()
            ->withCount(['auditIssues as audit_open_count' => fn ($q) => $q->where('status', 'open')])
            ->get()
            ->filter(fn ($p) => ($p->audit_open_count ?? 0) > 0)
            ->values();

        $projectIds = $user->projects()->pluck('id')->all();

        $recentDeployments = $projectIds === [] ? collect() : Deployment::query()
            ->whereIn('project_id', $projectIds)
            ->where('action', 'deploy')
            ->latest('started_at')
            ->limit(10)
            ->with(['project', 'triggeredBy'])
            ->get();

        $healthHistory = [];
        foreach ($monitoredProjects as $project) {
            $healthHistory[$project->id] = collect($project->healthHistory())
                ->map(fn (array $entry): object => (object) [
                    'status' => (string) ($entry['deployment_status'] ?? (($entry['status'] ?? '') === 'ok' ? 'success' : 'failed')),
                    'inconclusive' => ($entry['deployment_status'] ?? null) === 'inconclusive',
                    'started_at' => $entry['checked_at'] ?? null,
                    'http_status' => $entry['http_status'] ?? null,
                ])
                ->take(-30)
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
