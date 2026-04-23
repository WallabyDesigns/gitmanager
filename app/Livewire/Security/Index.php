<?php

namespace App\Livewire\Security;

use App\Models\AppUpdate;
use App\Models\AuditIssue;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\SecurityAlert;
use App\Services\AuditService;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use App\Services\EditionService;
use App\Services\SettingsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $tab = 'current';
    public bool $projectShell = false;
    public bool $sslVerifyEnabled = true;

    public function mount(SettingsService $settings): void
    {
        $this->projectShell = request()->routeIs('projects.action-center');
        $this->sslVerifyEnabled = (bool) ($settings->get(
            'system.github_ssl_verify',
            (bool) config('services.github.verify_ssl', true)
        ));
    }

    public function render()
    {
        $projectShell = $this->projectShell;
        $canSyncAlerts = Auth::user()?->isAdmin() ?? false;

        $alerts = $this->alertsQuery()
            ->where('state', 'open');

        $auditIssues = $this->auditIssuesQuery()
            ->where('status', 'open');

        $dependencyProjects = $this->dependencyIssueProjects();
        $dependencyIssueCount = $dependencyProjects->count();

        $latestUpdate = $canSyncAlerts
            ? AppUpdate::query()->orderByDesc('started_at')->first()
            : null;
        $updateIssueCount = $latestUpdate && $latestUpdate->status === 'failed' ? 1 : 0;
        $openCount = SecurityAlert::query()
            ->where('state', 'open')
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->count()
            + AuditIssue::query()
                ->where('status', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                ->count()
            + $dependencyIssueCount;

        return view('livewire.security.index', [
            'alerts' => $alerts->orderByDesc('alert_created_at')->get(),
            'openCount' => $openCount,
            'actionableCount' => $openCount + $updateIssueCount,
            'appUpdateFailed' => $latestUpdate && $latestUpdate->status === 'failed',
            'latestUpdate' => $latestUpdate,
            'sslVerifyEnabled' => $this->sslVerifyEnabled,
            'auditIssues' => $auditIssues->orderByDesc('detected_at')->get(),
            'dependencyProjects' => $dependencyProjects,
            'dependencyIssueCount' => $dependencyIssueCount,
            'projectShell' => $projectShell,
            'canSyncAlerts' => $canSyncAlerts,
            'canAttemptResolution' => true,
        ])->layout('layouts.app', [
            'title' => $projectShell ? 'Action Center' : 'Security',
            'header' => $projectShell
                ? view('livewire.projects.partials.action-center-header')
                : view('livewire.security.partials.header'),
        ]);
    }

    public function sync(): void
    {
        if (! (Auth::user()?->isAdmin() ?? false)) {
            $this->dispatch('notify', message: 'Only administrators can sync security alerts.');

            return;
        }

        Artisan::call('security:sync');
        $this->dispatch('notify', message: 'Security alerts synced.');
    }

    public function resolveAll(
        DeploymentQueueService $queue,
        DeploymentService $deployment,
        AuditService $audit,
        EditionService $edition,
    ): void {
        $plans = $this->resolutionPlans();
        if ($plans === []) {
            $this->dispatch('notify', message: 'No actionable issues are available to resolve.');

            return;
        }

        $summary = [
            'projects' => 0,
            'actions' => 0,
            'skipped' => 0,
        ];

        foreach ($plans as $plan) {
            $result = $this->attemptProjectResolution(
                $plan['project'],
                (bool) $plan['audit'],
                (bool) $plan['composer_update'],
                (bool) $plan['npm_update'],
                'action_center_bulk_resolve',
                false,
                false,
                $queue,
                $deployment,
                $audit,
                $edition,
            );

            if ($result['skipped']) {
                $summary['skipped']++;
                continue;
            }

            if ($result['actions'] > 0) {
                $summary['projects']++;
                $summary['actions'] += $result['actions'];
            }
        }

        $this->dispatchResolutionSummary($summary, $this->queueEnabled());
    }

    public function resolveAllForce(
        DeploymentQueueService $queue,
        DeploymentService $deployment,
        AuditService $audit,
        EditionService $edition,
    ): void {
        $plans = $this->resolutionPlans();
        if ($plans === []) {
            $this->dispatch('notify', message: 'No actionable issues are available to resolve.');

            return;
        }

        $summary = [
            'projects' => 0,
            'actions' => 0,
            'skipped' => 0,
        ];

        foreach ($plans as $plan) {
            $result = $this->attemptProjectResolution(
                $plan['project'],
                (bool) $plan['audit'],
                (bool) $plan['composer_update'],
                (bool) $plan['npm_update'],
                'action_center_bulk_force_resolve',
                false,
                true,
                $queue,
                $deployment,
                $audit,
                $edition,
            );

            if ($result['skipped']) {
                $summary['skipped']++;
                continue;
            }

            if ($result['actions'] > 0) {
                $summary['projects']++;
                $summary['actions'] += $result['actions'];
            }
        }

        $this->dispatchResolutionSummary($summary, $this->queueEnabled(), true);
    }

    public function resolveDependencyProject(
        int $projectId,
        DeploymentQueueService $queue,
        DeploymentService $deployment,
        AuditService $audit,
        EditionService $edition,
    ): void {
        $project = $this->ownedProject($projectId);
        $this->authorize('update', $project);

        $composerIssue = in_array((string) $project->getAttribute('last_composer_status'), ['failed', 'warning'], true);
        $npmIssue = in_array((string) $project->getAttribute('last_npm_status'), ['failed', 'warning'], true);

        $this->dispatchSingleResolutionResult(
            $project,
            $this->attemptProjectResolution(
                $project,
                true,
                $composerIssue,
                $npmIssue,
                'action_center_dependency_issue',
                true,
                false,
                $queue,
                $deployment,
                $audit,
                $edition,
            ),
        );
    }

    public function resolveDependencyProjectForce(
        int $projectId,
        DeploymentQueueService $queue,
        DeploymentService $deployment,
        AuditService $audit,
        EditionService $edition,
    ): void {
        $project = $this->ownedProject($projectId);
        $this->authorize('update', $project);

        $composerIssue = in_array((string) $project->getAttribute('last_composer_status'), ['failed', 'warning'], true);
        $npmIssue = in_array((string) $project->getAttribute('last_npm_status'), ['failed', 'warning'], true);

        $this->dispatchSingleResolutionResult(
            $project,
            $this->attemptProjectResolution(
                $project,
                true,
                $composerIssue,
                $npmIssue,
                'action_center_dependency_issue_force',
                true,
                true,
                $queue,
                $deployment,
                $audit,
                $edition,
            ),
            true,
        );
    }

    public function resolveSecurityAlert(
        int $alertId,
        DeploymentQueueService $queue,
        DeploymentService $deployment,
        AuditService $audit,
        EditionService $edition,
    ): void {
        $alert = SecurityAlert::query()
            ->where('id', $alertId)
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project')
            ->firstOrFail();

        $project = $alert->project;
        if (! $project) {
            abort(404);
        }

        $this->authorize('update', $project);

        $this->dispatchSingleResolutionResult(
            $project,
            $this->attemptProjectResolution(
                $project,
                true,
                $this->alertTargetsComposer($alert),
                $this->alertTargetsNpm($alert),
                'action_center_security_alert',
                true,
                false,
                $queue,
                $deployment,
                $audit,
                $edition,
            ),
        );
    }

    public function resolveSecurityAlertForce(
        int $alertId,
        DeploymentQueueService $queue,
        DeploymentService $deployment,
        AuditService $audit,
        EditionService $edition,
    ): void {
        $alert = SecurityAlert::query()
            ->where('id', $alertId)
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project')
            ->firstOrFail();

        $project = $alert->project;
        if (! $project) {
            abort(404);
        }

        $this->authorize('update', $project);

        $this->dispatchSingleResolutionResult(
            $project,
            $this->attemptProjectResolution(
                $project,
                true,
                $this->alertTargetsComposer($alert),
                $this->alertTargetsNpm($alert),
                'action_center_security_alert_force',
                true,
                true,
                $queue,
                $deployment,
                $audit,
                $edition,
            ),
            true,
        );
    }

    public function resolveAuditIssue(
        int $issueId,
        DeploymentQueueService $queue,
        DeploymentService $deployment,
        AuditService $audit,
        EditionService $edition,
    ): void {
        $issue = AuditIssue::query()
            ->where('id', $issueId)
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project')
            ->firstOrFail();

        $project = $issue->project;
        if (! $project) {
            abort(404);
        }

        $this->authorize('update', $project);

        $tool = strtolower(trim((string) $issue->tool));
        $this->dispatchSingleResolutionResult(
            $project,
            $this->attemptProjectResolution(
                $project,
                true,
                $tool === 'composer',
                $tool === 'npm',
                'action_center_audit_issue',
                true,
                false,
                $queue,
                $deployment,
                $audit,
                $edition,
            ),
        );
    }

    public function resolveAuditIssueForce(
        int $issueId,
        DeploymentQueueService $queue,
        DeploymentService $deployment,
        AuditService $audit,
        EditionService $edition,
    ): void {
        $issue = AuditIssue::query()
            ->where('id', $issueId)
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project')
            ->firstOrFail();

        $project = $issue->project;
        if (! $project) {
            abort(404);
        }

        $this->authorize('update', $project);

        $tool = strtolower(trim((string) $issue->tool));
        $this->dispatchSingleResolutionResult(
            $project,
            $this->attemptProjectResolution(
                $project,
                true,
                $tool === 'composer',
                $tool === 'npm',
                'action_center_audit_issue_force',
                true,
                true,
                $queue,
                $deployment,
                $audit,
                $edition,
            ),
            true,
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Project>
     */
    private function dependencyIssueProjects()
    {
        return Project::query()
            ->where('user_id', Auth::id())
            ->addSelect([
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
            ])
            ->get()
            ->filter(function (Project $project): bool {
                return in_array($project->getAttribute('last_composer_status'), ['failed', 'warning'], true)
                    || in_array($project->getAttribute('last_npm_status'), ['failed', 'warning'], true);
            })
            ->values();
    }

    private function alertsQuery()
    {
        return SecurityAlert::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project');
    }

    private function auditIssuesQuery()
    {
        return AuditIssue::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project');
    }

    /**
     * @return array<int, array{project: Project, audit: bool, composer_update: bool, npm_update: bool}>
     */
    private function resolutionPlans(): array
    {
        $plans = [];

        foreach ($this->dependencyIssueProjects() as $project) {
            $plans[$project->id] = [
                'project' => $project,
                'audit' => true,
                'composer_update' => in_array((string) $project->getAttribute('last_composer_status'), ['failed', 'warning'], true),
                'npm_update' => in_array((string) $project->getAttribute('last_npm_status'), ['failed', 'warning'], true),
            ];
        }

        foreach ($this->alertsQuery()->where('state', 'open')->get() as $alert) {
            $project = $alert->project;
            if (! $project) {
                continue;
            }

            $plan = $plans[$project->id] ?? [
                'project' => $project,
                'audit' => true,
                'composer_update' => false,
                'npm_update' => false,
            ];

            $plan['composer_update'] = $plan['composer_update'] || $this->alertTargetsComposer($alert);
            $plan['npm_update'] = $plan['npm_update'] || $this->alertTargetsNpm($alert);
            $plans[$project->id] = $plan;
        }

        foreach ($this->auditIssuesQuery()->where('status', 'open')->get() as $issue) {
            $project = $issue->project;
            if (! $project) {
                continue;
            }

            $plan = $plans[$project->id] ?? [
                'project' => $project,
                'audit' => true,
                'composer_update' => false,
                'npm_update' => false,
            ];

            $tool = strtolower(trim((string) $issue->tool));
            $plan['composer_update'] = $plan['composer_update'] || $tool === 'composer';
            $plan['npm_update'] = $plan['npm_update'] || $tool === 'npm';
            $plans[$project->id] = $plan;
        }

        return array_values($plans);
    }

    /**
     * @return array{actions:int, skipped:bool}
     */
    private function attemptProjectResolution(
        Project $project,
        bool $auditFirst,
        bool $composerUpdate,
        bool $npmUpdate,
        string $source,
        bool $runImmediatelyWhenIdle,
        bool $forceNpmFix,
        DeploymentQueueService $queue,
        DeploymentService $deployment,
        AuditService $audit,
        EditionService $edition,
    ): array {
        $actions = 0;

        if ($this->projectPermissionsLocked($project)) {
            return [
                'actions' => 0,
                'skipped' => true,
            ];
        }

        [$composerUpdate, $npmUpdate] = $this->resolveUpdateTargets(
            $project,
            $composerUpdate,
            $npmUpdate,
            $deployment,
        );

        $shouldAudit = $auditFirst && $edition->current() === EditionService::ENTERPRISE;

        if (! $shouldAudit && ! $composerUpdate && ! $npmUpdate) {
            return [
                'actions' => 0,
                'skipped' => true,
                'mode' => 'skipped',
            ];
        }

        if ($this->queueEnabled()) {
            $runImmediately = $runImmediatelyWhenIdle && $queue->isIdle();
            $queuedItems = [];

            if ($shouldAudit) {
                $queuedItems[] = $queue->enqueue($project, 'audit_project', [
                    'auto_fix' => true,
                    'send_email' => true,
                    'source' => $source,
                ], Auth::user());
                $actions++;
            }

            if ($composerUpdate) {
                $queuedItems[] = $queue->enqueue($project, 'composer_update', [
                    'source' => $source,
                ], Auth::user());
                $actions++;
            }

            if ($npmUpdate) {
                $queuedItems[] = $queue->enqueue($project, $forceNpmFix ? 'npm_audit_fix_force' : 'npm_update', [
                    'source' => $source,
                ], Auth::user());
                $actions++;
            }

            if ($runImmediately) {
                foreach ($queuedItems as $queuedItem) {
                    $freshItem = $queuedItem->fresh();
                    if (! $freshItem || $freshItem->status !== 'queued') {
                        continue;
                    }

                    $queue->processItem($freshItem);
                }

                return [
                    'actions' => $actions,
                    'skipped' => $actions === 0,
                    'mode' => 'executed',
                ];
            }

            return [
                'actions' => $actions,
                'skipped' => $actions === 0,
                'mode' => 'queued',
            ];
        }

        if ($shouldAudit) {
            try {
                $audit->auditProject($project, Auth::user(), true, true);
                $actions++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($composerUpdate) {
            try {
                $deployment->composerUpdate($project, Auth::user());
                $actions++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($npmUpdate) {
            try {
                if ($forceNpmFix) {
                    $deployment->npmAuditFix($project, Auth::user(), true);
                } else {
                    $deployment->npmUpdate($project, Auth::user());
                }
                $actions++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return [
            'actions' => $actions,
            'skipped' => $actions === 0,
            'mode' => 'executed',
        ];
    }

    private function dispatchSingleResolutionResult(Project $project, array $result, bool $forced = false): void
    {
        if ($result['skipped']) {
            $message = $this->projectPermissionsLocked($project)
                ? 'Permissions need fixing before '.$project->name.' can be repaired from the Action Center.'
                : 'No automated repair is available for '.$project->name.' yet.';

            $this->dispatch('notify', message: $message, type: 'warning');

            return;
        }

        $label = $forced ? 'force repair action(s)' : 'repair action(s)';
        $message = match ($result['mode'] ?? 'queued') {
            'executed' => "Started {$result['actions']} {$label} for {$project->name} immediately.",
            default => "Queued {$result['actions']} {$label} for {$project->name}.",
        };

        $this->dispatch('notify', message: $message);
    }

    /**
     * @param array{projects:int, actions:int, skipped:int} $summary
     */
    private function dispatchResolutionSummary(array $summary, bool $queueEnabled, bool $forced = false): void
    {
        if ($summary['actions'] === 0) {
            $message = $summary['skipped'] > 0
                ? 'No repair actions were started. Some projects may need permission fixes first.'
                : 'No automated repair actions were available.';

            $this->dispatch('notify', message: $message, type: 'warning');

            return;
        }

        $verb = $queueEnabled ? 'Queued' : 'Attempted';
        $label = $forced ? 'force repair action(s)' : 'repair action(s)';
        $message = "{$verb} {$summary['actions']} {$label} across {$summary['projects']} project(s).";

        if ($summary['skipped'] > 0) {
            $message .= ' Skipped '.$summary['skipped'].' project(s).';
        }

        $this->dispatch('notify', message: $message);
    }

    private function ownedProject(int $projectId): Project
    {
        return Project::query()
            ->where('id', $projectId)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    private function alertTargetsComposer(SecurityAlert $alert): bool
    {
        $ecosystem = strtolower(trim((string) $alert->ecosystem));
        $manifest = strtolower(trim((string) $alert->manifest_path));

        return $ecosystem === 'composer'
            || str_ends_with($manifest, 'composer.json')
            || str_ends_with($manifest, 'composer.lock');
    }

    private function alertTargetsNpm(SecurityAlert $alert): bool
    {
        $ecosystem = strtolower(trim((string) $alert->ecosystem));
        $manifest = strtolower(trim((string) $alert->manifest_path));

        return in_array($ecosystem, ['npm', 'node'], true)
            || str_ends_with($manifest, 'package.json')
            || str_ends_with($manifest, 'package-lock.json')
            || str_ends_with($manifest, 'npm-shrinkwrap.json')
            || str_ends_with($manifest, 'yarn.lock')
            || str_ends_with($manifest, 'pnpm-lock.yaml');
    }

    private function projectPermissionsLocked(Project $project): bool
    {
        return ! $project->ftp_enabled
            && ! $project->ssh_enabled
            && (bool) $project->permissions_locked;
    }

    private function queueEnabled(): bool
    {
        return (bool) config('gitmanager.deploy_queue.enabled', true);
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function resolveUpdateTargets(
        Project $project,
        bool $composerUpdate,
        bool $npmUpdate,
        DeploymentService $deployment,
    ): array {
        if ($composerUpdate || $npmUpdate) {
            return [$composerUpdate, $npmUpdate];
        }

        return [
            $deployment->hasComposer($project),
            $deployment->hasNpm($project),
        ];
    }
}
