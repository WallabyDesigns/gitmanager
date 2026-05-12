<?php

namespace App\Livewire\Projects;

use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Services\AuditService;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use App\Services\EditionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
        $projects = Project::query()
            ->get();

        $this->runHealthChecks($service, $projects, true);

        $this->dispatch('notify', message: 'Health checks complete.');
    }

    public function checkAllUpdates(DeploymentService $service): void
    {
        $projects = Project::query()
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

        $projects = Project::query()
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

    public function mount(): void
    {
        app(DeploymentService::class)->releaseStaleRunningDeployments();
        app(DeploymentQueueService::class)->releaseStaleRunning();
    }

    public function render()
    {
        $baseQuery = Project::query()
            ->with('ftpAccount')
            ->withCount([
                'auditIssues as audit_open_count' => fn ($query) => $query->where('status', 'open'),
            ])
            ->addSelect([
                'last_successful_deploy_at' => Deployment::query()
                    ->select('started_at')
                    ->whereColumn('project_id', 'projects.id')
                    ->where('action', 'deploy')
                    ->where('status', 'success')
                    ->latest('started_at')
                    ->limit(1),
                'last_composer_status' => Deployment::query()
                    ->select('status')
                    ->whereColumn('project_id', 'projects.id')
                    ->whereIn('action', ['composer_install', 'composer_update', 'composer_audit'])
                    ->latest('started_at')
                    ->limit(1),
                'last_npm_status' => Deployment::query()
                    ->select('status')
                    ->whereColumn('project_id', 'projects.id')
                    ->whereIn('action', ['npm_install', 'npm_update', 'npm_audit', 'npm_audit_fix', 'npm_audit_fix_force'])
                    ->latest('started_at')
                    ->limit(1),
            ]);

        $search = trim($this->search);
        if ($search !== '') {
            $baseQuery->where(function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where('name', 'like', $like)
                    ->orWhere('directory_path', 'like', $like)
                    ->orWhere('local_path', 'like', $like)
                    ->orWhere('repo_url', 'like', $like)
                    ->orWhere('site_url', 'like', $like);
            });
        }

        if ($this->filter === 'health') {
            $baseQuery->withHealthMonitoring()->where('health_status', 'na');
        } elseif ($this->filter === 'permissions') {
            $baseQuery->where('permissions_locked', true)
                ->where('ftp_enabled', false)
                ->where('ssh_enabled', false);
        }

        $projects = (clone $baseQuery)
            ->latest()
            ->get();
        $projectTree = $this->buildProjectTree($projects);

        $projectIds = $projects->pluck('id')->all();
        $queueProjectIds = $projectIds === []
            ? []
            : DeploymentQueueItem::query()
                ->whereIn('project_id', $projectIds)
                ->whereIn('status', ['queued', 'running'])
                ->pluck('project_id')
                ->all();


        $runningAuditDeployments = $projectIds === []
            ? []
            : Deployment::query()
                ->whereIn('project_id', $projectIds)
                ->where('status', 'running')
                ->whereIn('action', $this->auditActions())
                ->pluck('project_id')
                ->all();

        $runningDeploymentsNonAudit = $projectIds === []
            ? []
            : Deployment::query()
                ->whereIn('project_id', $projectIds)
                ->where('status', 'running')
                ->whereNotIn('action', $this->auditActions())
                ->pluck('project_id')
                ->all();

        $runningQueueItems = $projectIds === []
            ? []
            : DeploymentQueueItem::query()
                ->whereIn('project_id', $projectIds)
                ->where('status', 'running')
                ->pluck('project_id')
                ->all();

        $buildInProcess = array_values(array_unique(array_merge($runningDeploymentsNonAudit, $runningQueueItems)));
        $auditInProcess = array_values(array_unique($runningAuditDeployments));

        return view('livewire.projects.index', [
            'projects' => $projects,
            'projectTree' => $projectTree,
            'buildInProcess' => $buildInProcess,
            'auditInProcess' => $auditInProcess,
            'queueProjects' => $queueProjectIds,
            'counts' => [
                'all' => Project::query()->count(),
                'health' => Project::query()
                    ->withHealthMonitoring()
                    ->where('health_status', 'na')
                    ->count(),
                'permissions' => Project::query()
                    ->where('permissions_locked', true)
                    ->where('ftp_enabled', false)
                    ->where('ssh_enabled', false)
                    ->count(),
            ],
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.index-header'),
            'title' => __('Projects'),
        ]);
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{
     *     name: string,
     *     path: string,
     *     project_count: int,
     *     issue_counts: array<string, int>,
     *     directories: array<string, array<string, mixed>>,
     *     projects: array<int, Project>
     * }
     */
    private function buildProjectTree($projects): array
    {
        $root = [
            'name' => 'Projects',
            'path' => '',
            'project_count' => 0,
            'issue_counts' => $this->emptyIssueCounts(),
            'directories' => [],
            'projects' => [],
        ];

        foreach ($projects as $project) {
            $segments = $this->directorySegments((string) ($project->directory_path ?? ''));
            $issueFlags = $this->projectIssueFlags($project);
            $this->insertProjectIntoTree($root, $segments, $project, $issueFlags);
        }

        $this->sortTreeNode($root);

        return $root;
    }

    /**
     * @param array{
     *     name: string,
     *     path: string,
     *     project_count: int,
     *     issue_counts: array<string, int>,
     *     directories: array<string, array<string, mixed>>,
     *     projects: array<int, Project>
     * } $node
     * @param  array<int, string>  $segments
     * @param  array<string, int>  $issueFlags
     */
    private function insertProjectIntoTree(array &$node, array $segments, Project $project, array $issueFlags): void
    {
        $node['project_count'] = (int) ($node['project_count'] ?? 0) + 1;
        $this->applyIssueCounts($node, $issueFlags);

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
        $key = Str::lower($label);

        if (! isset($node['directories'][$key])) {
            $path = trim(((string) ($node['path'] ?? '')).'/'.$label, '/');
            $node['directories'][$key] = [
                'name' => $label,
                'path' => $path,
                'project_count' => 0,
                'issue_counts' => $this->emptyIssueCounts(),
                'directories' => [],
                'projects' => [],
            ];
        }

        $this->insertProjectIntoTree($node['directories'][$key], $segments, $project, $issueFlags);
    }

    /**
     * @return array<int, string>
     */
    private function directorySegments(string $path): array
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('/\/+/', '/', $normalized) ?? $normalized;
        $normalized = trim($normalized);
        $normalized = trim($normalized, '/');

        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $segment) => trim($segment), explode('/', $normalized)),
            static fn (string $segment) => $segment !== ''
        ));
    }

    /**
     * @param array{
     *     name: string,
     *     path: string,
     *     project_count: int,
     *     issue_counts: array<string, int>,
     *     directories: array<string, array<string, mixed>>,
     *     projects: array<int, Project>
     * } $node
     */
    private function sortTreeNode(array &$node): void
    {
        uasort($node['directories'], static function (array $left, array $right): int {
            return strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        usort($node['projects'], static function (Project $left, Project $right): int {
            return strnatcasecmp((string) $left->name, (string) $right->name);
        });

        foreach ($node['directories'] as &$child) {
            $this->sortTreeNode($child);
        }
        unset($child);
    }

    /**
     * @return array<string, int>
     */
    private function emptyIssueCounts(): array
    {
        return [
            'permissions' => 0,
            'updates' => 0,
            'vulnerabilities' => 0,
            'composer' => 0,
            'npm' => 0,
            'ftp' => 0,
            'ssh' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function projectIssueFlags(Project $project): array
    {
        $ftpNeedsTest = $project->ftp_enabled
            && $project->ftpAccount
            && $project->ftpAccount->ftpNeedsTest();
        $sshNeedsTest = $project->ssh_enabled
            && $project->ftpAccount
            && $project->ftpAccount->sshNeedsTest();

        $permissionsIssue = ! $project->ftp_enabled
            && ! $project->ssh_enabled
            && $project->permissions_locked;
        $ftpIssue = $project->ftp_enabled
            && $project->ftpAccount
            && in_array($project->ftpAccount->ftp_test_status, ['error', 'warning'], true)
            && ! $ftpNeedsTest;
        $sshIssue = $project->ssh_enabled
            && $project->ftpAccount
            && in_array($project->ftpAccount->ssh_test_status, ['error', 'warning'], true)
            && ! $sshNeedsTest;
        $composerIssue = in_array($project->last_composer_status ?? null, ['failed', 'warning'], true);
        $npmIssue = in_array($project->last_npm_status ?? null, ['failed', 'warning'], true);
        $vulnerabilityIssue = (int) ($project->audit_open_count ?? 0) > 0;

        return [
            'permissions' => $permissionsIssue ? 1 : 0,
            'updates' => $project->updates_available ? 1 : 0,
            'vulnerabilities' => $vulnerabilityIssue ? 1 : 0,
            'composer' => $composerIssue ? 1 : 0,
            'npm' => $npmIssue ? 1 : 0,
            'ftp' => $ftpIssue ? 1 : 0,
            'ssh' => $sshIssue ? 1 : 0,
        ];
    }

    /**
     * @param array{
     *     name: string,
     *     path: string,
     *     project_count: int,
     *     issue_counts: array<string, int>,
     *     directories: array<string, array<string, mixed>>,
     *     projects: array<int, Project>
     * } $node
     * @param  array<string, int>  $issueFlags
     */
    private function applyIssueCounts(array &$node, array $issueFlags): void
    {
        if (! isset($node['issue_counts']) || ! is_array($node['issue_counts'])) {
            $node['issue_counts'] = $this->emptyIssueCounts();
        }

        foreach ($this->emptyIssueCounts() as $key => $default) {
            $node['issue_counts'][$key] = (int) ($node['issue_counts'][$key] ?? $default) + (int) ($issueFlags[$key] ?? 0);
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
     * @return array<int, string>
     */
    private function auditActions(): array
    {
        return [
            'composer_audit',
            'npm_audit',
            'npm_audit_fix',
            'npm_audit_fix_force',
        ];
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
