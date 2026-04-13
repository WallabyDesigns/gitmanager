<?php

namespace App\Livewire\Projects;

use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Services\DeploymentService;
use App\Services\DeploymentQueueService;
use App\Services\AuditService;
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

    public function auditAllProjects(AuditService $audit): void
    {
        $projects = Auth::user()
            ->projects()
            ->get();

        $results = $audit->auditProjects($projects, Auth::user(), true, true);
        $this->dispatchAuditToast($results);
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
                    ->whereIn('action', ['npm_install', 'npm_update', 'npm_audit_fix', 'npm_audit_fix_force'])
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
            'buildInProcess' => $buildInProcess,
            'auditInProcess' => $auditInProcess,
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
        return $project->hasSuccessfulDeployment();
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
                $wasAvailable = (bool) $project->updates_available;
                try {
                    $hasUpdates = $service->checkForUpdates($project);
                } catch (\Throwable $exception) {
                    $this->markUpdateCheckAttempt($project);
                    continue;
                }

                $queued = false;
                if (! $wasAvailable && $hasUpdates && $project->auto_deploy && $this->queueEnabled()) {
                    app(DeploymentQueueService::class)->enqueue($project, 'deploy', ['reason' => 'auto_update'], Auth::user());
                    $queued = true;
                }

                if (! $queued) {
                    if ($project->hasSuccessfulDeployment()) {
                        $service->checkHealth($project, false, $autoNotify);
                    }
                }
            }
        }

        $service->flushHealthNotifications();
    }

    private function markUpdateCheckAttempt(\App\Models\Project $project): void
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
            'npm_audit_fix',
            'npm_audit_fix_force',
        ];
    }

    /**
     * @param array<int, array<string, array<string, mixed>>> $results
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
     * @param array<int, array<string, array<string, mixed>>> $results
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
