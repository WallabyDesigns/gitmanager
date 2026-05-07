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
            $this->queueBulkAudit($projects);

            return;
        }

        $results = $audit->auditProjects($projects, Auth::user(), true, true);
        $this->dispatchAuditToast($results);
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    private function queueBulkAudit(Collection $projects): void
    {
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
    }

    public function render()
    {
        $user = Auth::user();

        $allProjects = $user->projects()
            ->withCount([
                'auditIssues as audit_open_count' => fn ($q) => $q->where('status', 'open'),
                'deployments as deployments_today_count' => fn ($q) => $q
                    ->where('action', 'deploy')
                    ->where('started_at', '>=', now()->startOfDay()),
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
        $projectTree = $this->buildProjectTree($allProjects);

        return view('livewire.dashboard.index', [
            'totalProjects' => $allProjects->count(),
            'projectTree' => $projectTree,
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
     *   container_tree: array<string, mixed>,
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
            'container_tree' => $this->emptyContainerNode('Containers', ''),
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
            $base['container_tree'] = $this->buildContainerTree($base['containers_list']);
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

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    private function buildProjectTree(Collection $projects): array
    {
        $root = $this->emptyProjectNode(__('Projects'), '');

        foreach ($projects as $project) {
            $segments = $this->directorySegments((string) ($project->directory_path ?? ''));
            $this->insertProjectIntoTree($root, $segments, $project);
        }

        $this->sortTreeNode($root);

        return $root;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProjectNode(string $name, string $path): array
    {
        return [
            'name' => $name,
            'path' => $path,
            'stats' => [
                'total' => 0,
                'monitored' => 0,
                'healthy' => 0,
                'down' => 0,
                'unknown' => 0,
                'updates' => 0,
                'vulnerabilities' => 0,
                'deployments_today' => 0,
            ],
            'directories' => [],
            'projects' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $segments
     */
    private function insertProjectIntoTree(array &$node, array $segments, Project $project): void
    {
        $this->applyProjectStats($node, $project);

        if ($segments === []) {
            $node['projects'][] = $project;

            return;
        }

        $segment = array_shift($segments);
        if (! is_string($segment) || trim($segment) === '') {
            $node['projects'][] = $project;

            return;
        }

        $label = trim($segment);
        $key = strtolower($label);
        if (! isset($node['directories'][$key])) {
            $path = trim(((string) ($node['path'] ?? '')).'/'.$label, '/');
            $node['directories'][$key] = $this->emptyProjectNode($label, $path);
        }

        $this->insertProjectIntoTree($node['directories'][$key], $segments, $project);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function applyProjectStats(array &$node, Project $project): void
    {
        $node['stats']['total']++;
        $node['stats']['updates'] += $project->updates_available ? 1 : 0;
        $node['stats']['vulnerabilities'] += (int) ($project->audit_open_count ?? 0);
        $node['stats']['deployments_today'] += (int) ($project->deployments_today_count ?? 0);

        if (! $project->hasHealthMonitoring()) {
            return;
        }

        $node['stats']['monitored']++;
        if ($project->health_status === 'ok') {
            $node['stats']['healthy']++;
        } elseif ($project->health_status === 'na') {
            $node['stats']['down']++;
        } else {
            $node['stats']['unknown']++;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $containers
     * @return array<string, mixed>
     */
    private function buildContainerTree(array $containers): array
    {
        $root = $this->emptyContainerNode(__('Containers'), '');

        foreach ($containers as $container) {
            $segments = $this->containerSegments($container);
            $this->insertContainerIntoTree($root, $segments, $container);
        }

        $this->sortTreeNode($root);

        return $root;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyContainerNode(string $name, string $path): array
    {
        return [
            'name' => $name,
            'path' => $path,
            'stats' => [
                'total' => 0,
                'running' => 0,
                'stopped' => 0,
            ],
            'directories' => [],
            'containers' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $segments
     * @param  array<string, mixed>  $container
     */
    private function insertContainerIntoTree(array &$node, array $segments, array $container): void
    {
        $this->applyContainerStats($node, $container);

        if ($segments === []) {
            $node['containers'][] = $container;

            return;
        }

        $segment = array_shift($segments);
        if (! is_string($segment) || trim($segment) === '') {
            $node['containers'][] = $container;

            return;
        }

        $label = trim($segment);
        $key = strtolower($label);
        if (! isset($node['directories'][$key])) {
            $path = trim(((string) ($node['path'] ?? '')).'/'.$label, '/');
            $node['directories'][$key] = $this->emptyContainerNode($label, $path);
        }

        $this->insertContainerIntoTree($node['directories'][$key], $segments, $container);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $container
     */
    private function applyContainerStats(array &$node, array $container): void
    {
        $node['stats']['total']++;
        if (($container['State'] ?? '') === 'running') {
            $node['stats']['running']++;
        } else {
            $node['stats']['stopped']++;
        }
    }

    /**
     * @param  array<string, mixed>  $container
     * @return array<int, string>
     */
    private function containerSegments(array $container): array
    {
        $labels = $this->containerLabels($container['Labels'] ?? '');
        $composeProject = trim((string) ($labels['com.docker.compose.project'] ?? ''));
        if ($composeProject !== '') {
            return [__('Compose'), $composeProject];
        }

        $swarmService = trim((string) ($labels['com.docker.swarm.service.name'] ?? ''));
        if ($swarmService !== '') {
            return [__('Swarm'), $swarmService];
        }

        $image = trim((string) ($container['Image'] ?? ''));
        if (str_contains($image, '/')) {
            $parts = array_values(array_filter(explode('/', $image), static fn (string $part): bool => trim($part) !== ''));
            if (count($parts) > 1) {
                array_pop($parts);

                return array_merge([__('Images')], $parts);
            }
        }

        return [__('Standalone')];
    }

    /**
     * @return array<string, string>
     */
    private function containerLabels(mixed $labels): array
    {
        if (is_array($labels)) {
            return array_map(static fn ($value): string => (string) $value, $labels);
        }

        $parsed = [];
        foreach (explode(',', (string) $labels) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = trim($key);
            if ($key !== '') {
                $parsed[$key] = trim($value);
            }
        }

        return $parsed;
    }

    /**
     * @return array<int, string>
     */
    private function directorySegments(string $path): array
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('/\/+/', '/', $normalized) ?? $normalized;
        $normalized = trim($normalized, '/ ');

        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), explode('/', $normalized)),
            static fn (string $segment): bool => $segment !== ''
        ));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function sortTreeNode(array &$node): void
    {
        uasort($node['directories'], static function (array $left, array $right): int {
            return strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        foreach ($node['directories'] as &$child) {
            $this->sortTreeNode($child);
        }
        unset($child);

        if (isset($node['projects']) && is_array($node['projects'])) {
            usort($node['projects'], static function (Project $left, Project $right): int {
                return strnatcasecmp((string) $left->name, (string) $right->name);
            });
        }

        if (isset($node['containers']) && is_array($node['containers'])) {
            usort($node['containers'], static function (array $left, array $right): int {
                $leftName = ltrim((string) ($left['Names'] ?? $left['ID'] ?? ''), '/');
                $rightName = ltrim((string) ($right['Names'] ?? $right['ID'] ?? ''), '/');

                return strnatcasecmp($leftName, $rightName);
            });
        }
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
            $this->checkProjectUpdates($service, $project, $autoNotify);
        }

        $service->flushHealthNotifications();
    }

    private function checkProjectUpdates(DeploymentService $service, Project $project, bool $autoNotify): void
    {
        $updatesCheckedAt = $project->updates_checked_at;
        if (is_string($updatesCheckedAt)) {
            $updatesCheckedAt = Carbon::parse($updatesCheckedAt);
        }

        if ($updatesCheckedAt && $updatesCheckedAt->gte(now()->subMinutes(5))) {
            return;
        }

        $wasAvailable = (bool) $project->updates_available;
        try {
            $hasUpdates = $service->checkForUpdates($project);
        } catch (\Throwable) {
            $this->markUpdateCheckAttempt($project);

            return;
        }

        if (! $wasAvailable && $hasUpdates && $project->auto_deploy && $this->queueEnabled()) {
            app(DeploymentQueueService::class)->enqueue($project, 'deploy', ['reason' => 'auto_update'], Auth::user());

            return;
        }

        if ($project->hasSuccessfulDeployment()) {
            $service->checkHealth($project, false, $autoNotify);
        }
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

            [$projectRemaining, $projectFailed] = $this->tallyProjectResults($projectResults);
            $failed += $projectFailed;

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

    /**
     * @param  array<string, array<string, mixed>>  $projectResults
     * @return array{int, int}
     */
    private function tallyProjectResults(array $projectResults): array
    {
        $remaining = 0;
        $failed = 0;

        foreach ($projectResults as $result) {
            if (! is_array($result)) {
                continue;
            }

            if (($result['status'] ?? '') === 'failed') {
                $failed++;
            }

            $count = $result['remaining'] ?? null;
            if (is_numeric($count)) {
                $remaining += (int) $count;
            }
        }

        return [$remaining, $failed];
    }
}
