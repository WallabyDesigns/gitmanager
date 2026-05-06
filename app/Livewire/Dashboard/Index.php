<?php

namespace App\Livewire\Dashboard;

use App\Models\Deployment;
use App\Models\Project;
use App\Services\AuditService;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use App\Services\DockerService;
use App\Services\EditionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public function auditAllProjects(AuditService $audit): void
    {
        if (! $this->isEnterpriseEdition()) {
            $this->dispatch('notify', message: 'Automatic project audits are available in Enterprise Edition.', type: 'warning');
            $this->dispatch('gwm-open-enterprise-modal', feature: 'Automatic Project & Container Audits');

            return;
        }

        $projects = Auth::user()
            ->projects()
            ->get();

        if ($this->queueEnabled()) {
            $queued = 0;
            $existing = 0;
            $skipped = 0;

            foreach ($projects as $project) {
                if ($project->permissions_locked && ! $project->ftp_enabled && ! $project->ssh_enabled) {
                    $skipped++;

                    continue;
                }

                $item = app(DeploymentQueueService::class)->enqueue($project, 'audit_project', [
                    'auto_fix' => true,
                    'send_email' => true,
                    'source' => 'bulk_project_audit',
                ], Auth::user());

                if ($item->wasRecentlyCreated) {
                    $queued++;
                } else {
                    $existing++;
                }
            }

            $message = "Queued {$queued} project audit(s).";
            if ($existing > 0) {
                $message .= " {$existing} already queued.";
            }
            if ($skipped > 0) {
                $message .= " {$skipped} skipped because permissions are locked.";
            }

            $this->dispatch('notify', message: $message);

            return;
        }

        $results = $audit->auditProjects($projects, Auth::user(), true, true);
        $this->dispatchAuditToast($results);
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

    private function queueEnabled(): bool
    {
        return (bool) config('gitmanager.deploy_queue.enabled', true);
    }

    private function isEnterpriseEdition(): bool
    {
        return app(EditionService::class)->current() === EditionService::ENTERPRISE;
    }

    private function shouldAutoCheckHealth(Project $project): bool
    {
        return $project->hasSuccessfulDeployment();
    }

    /**
     * @param  Collection<int, Project>  $projects
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
     * @param  Collection<int, Project>  $projects
     */
    private function runUpdateChecks(DeploymentService $service, $projects, bool $autoNotify): void
    {
        foreach ($projects as $project) {
            $updatesCheckedAt = $project->updates_checked_at;
            if (is_string($updatesCheckedAt)) {
                $updatesCheckedAt = Carbon::parse($updatesCheckedAt);
            }

            if (! $updatesCheckedAt || $updatesCheckedAt->lt(now()->subMinutes(5))) {
                $wasAvailable = (bool) $project->updates_available;
                try {
                    $hasUpdates = $service->checkForUpdates($project);
                } catch (\Throwable) {
                    $this->markUpdateCheckAttempt($project);

                    continue;
                }

                $queued = false;
                if (! $wasAvailable && $hasUpdates && $project->auto_deploy && $this->queueEnabled()) {
                    app(DeploymentQueueService::class)->enqueue($project, 'deploy', ['reason' => 'auto_update'], Auth::user());
                    $queued = true;
                }

                if (! $queued && $project->hasSuccessfulDeployment()) {
                    $service->checkHealth($project, false, $autoNotify);
                }
            }
        }

        $service->flushHealthNotifications();
    }

    private function markUpdateCheckAttempt(Project $project): void
    {
        $project->last_checked_at = now();
        $project->updates_checked_at = now();
        $project->save();
    }

    /**
     * @param  array<int, array<string, array<string, mixed>>>  $results
     */
    private function dispatchAuditToast(array $results): void
    {
        $summary = $this->summarizeProjectAuditResults($results);

        if ($summary['remaining'] > 0) {
            $label = $summary['remaining'] === 1 ? '1 vulnerability' : "{$summary['remaining']} vulnerabilities";
            $projects = $summary['projects_with_issues'] === 1
                ? '1 project'
                : "{$summary['projects_with_issues']} projects";
            $this->dispatch('notify', message: "Vulnerabilities found in {$projects} ({$label} total). Open the Security tab to resolve them.", type: 'error');

            return;
        }

        if ($summary['failed'] > 0) {
            $this->dispatch('notify', message: 'Audit checks completed with errors. Check the logs for details.', type: 'warning');

            return;
        }

        $this->dispatch('notify', message: 'Audit checks complete. No vulnerabilities found.', type: 'success');
    }

    /**
     * @param  array<int, array<string, array<string, mixed>>>  $results
     * @return array{remaining: int, failed: int, projects_with_issues: int}
     */
    private function summarizeProjectAuditResults(array $results): array
    {
        $remaining = 0;
        $failed = 0;
        $projectsWithIssues = 0;

        foreach ($results as $projectResults) {
            if (! is_array($projectResults)) {
                continue;
            }

            $projectRemaining = 0;
            foreach ($projectResults as $result) {
                if (! is_array($result)) {
                    continue;
                }

                if (($result['status'] ?? '') === 'failed') {
                    $failed++;
                }

                $count = $result['remaining'] ?? null;
                if (is_numeric($count)) {
                    $projectRemaining += (int) $count;
                }
            }

            if ($projectRemaining > 0) {
                $projectsWithIssues++;
                $remaining += $projectRemaining;
            }
        }

        return [
            'remaining' => $remaining,
            'failed' => $failed,
            'projects_with_issues' => $projectsWithIssues,
        ];
    }
}
