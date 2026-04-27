<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Models\SecurityAlert;
use App\Models\AuditIssue;
use App\Services\EditionService;
use App\Services\AuditService;
use App\Services\DeploymentService;
use App\Services\DeploymentQueueService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $projectsTab = 'list';

    public Project $project;
    public string $previewCommit = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    public function render()
    {
        $logLimit = $this->logPreviewLimit();
        $logPreview = DB::raw($this->logPreviewSql('deployments.output_log', $logLimit).' as output_log');

        $deployments = $this->project->deployments()
            ->select($this->deploymentColumns())
            ->addSelect($logPreview)
            ->orderByDesc('started_at');
        $lastSuccessfulDeploy = (clone $deployments)
            ->where('action', 'deploy')
            ->where('status', 'success')
            ->first();

        $service = app(DeploymentService::class);
        $commits = $service->getRecentCommits($this->project, 3);
        $currentHead = $service->getCurrentHead($this->project);
        $activeCommit = $currentHead ?: $this->project->last_deployed_hash;
        $rollbackAvailable = $activeCommit
            ? $this->project->deployments()
                ->where('action', 'deploy')
                ->where('status', 'success')
                ->whereNotNull('to_hash')
                ->where('to_hash', '!=', $activeCommit)
                ->exists()
            : false;
        $auditOpenCount = AuditIssue::query()
            ->where('project_id', $this->project->id)
            ->where('status', 'open')
            ->count();
        $composerStatus = $this->project->deployments()
            ->whereIn('action', $this->composerActions())
            ->orderByDesc('started_at')
            ->value('status');
        $npmStatus = $this->project->deployments()
            ->whereIn('action', $this->npmActions())
            ->orderByDesc('started_at')
            ->value('status');
        $composerIssue = in_array($composerStatus, ['failed', 'warning'], true);
        $npmIssue = in_array($npmStatus, ['failed', 'warning'], true);
        $securityOpenCount = SecurityAlert::query()
            ->where('project_id', $this->project->id)
            ->where('state', 'open')
            ->count();
        $securityOpenCount += $auditOpenCount;
        $runningTask = $this->currentRunningTask();
        $deploymentRunning = $runningTask !== null;
        $seedService = app(\App\Services\ProjectSeedService::class);
        $envSeedPending = $seedService->hasSeed($this->project, '.env');
        $htaccessSeedPending = $seedService->hasSeed($this->project, '.htaccess');

        return view('livewire.projects.show', [
            'deployments' => (clone $deployments)->paginate(20),
            'recentDebug' => (clone $deployments)->take(3)->get(),
            'commits' => $commits,
            'activeCommit' => $activeCommit,
            'lastSuccessfulDeploy' => $lastSuccessfulDeploy,
            'rollbackAvailable' => $rollbackAvailable,
            'envTabEnabled' => $this->envTabEnabled(),
            'securityOpenCount' => $securityOpenCount,
            'auditOpenCount' => $auditOpenCount,
            'composerIssue' => $composerIssue,
            'npmIssue' => $npmIssue,
            'deploymentRunning' => $deploymentRunning,
            'runningTaskLabel' => $runningTask['label'] ?? 'Task',
            'isEnterprise' => $this->isEnterpriseEdition(),
            'envSeedPending' => $envSeedPending,
            'htaccessSeedPending' => $htaccessSeedPending,
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.show-header', [
                'project' => $this->project,
                'envSeedPending' => $envSeedPending,
                'htaccessSeedPending' => $htaccessSeedPending,
            ]),
            'title' => $this->project->name,
        ]);
    }

    public function refreshHealthStatus(DeploymentService $service): void
    {
        if (
            $this->shouldAutoCheckHealth($this->project)
            && (! $this->project->health_checked_at || $this->project->health_checked_at->lt(now()->subMinute()))
        ) {
            $service->checkHealth($this->project);
            $this->project->refresh();
        }

        $updatesCheckedAt = $this->project->updates_checked_at;
        if (is_string($updatesCheckedAt)) {
            $updatesCheckedAt = Carbon::parse($updatesCheckedAt);
        }

        if (! $updatesCheckedAt || $updatesCheckedAt->lt(now()->subMinutes(5))) {
            $wasAvailable = (bool) $this->project->updates_available;
            try {
                $hasUpdates = $service->checkForUpdates($this->project);
            } catch (\Throwable $exception) {
                $this->markUpdateCheckAttempt();
                return;
            }
            $this->project->refresh();

            if (! $wasAvailable && $hasUpdates && $this->project->auto_deploy && $this->queueEnabled()) {
                app(DeploymentQueueService::class)->enqueue($this->project, 'deploy', ['reason' => 'auto_update'], Auth::user());
            }
        }
    }

    public function deploy(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked()) {
            return;
        }

        if ($this->deploymentInProgress()) {
            $this->dispatch('notify', message: 'A deployment is already running for this project.');
            return;
        }

        app(DeploymentQueueService::class)->cancelQueuedGroup($this->project, 'deploy');
        if ($this->queueEnabled()) {
            $result = app(DeploymentQueueService::class)->enqueueForImmediateProcessing($this->project, 'deploy', ['reason' => 'manual_deploy'], Auth::user());
            $this->dispatch('notify', message: $result['started'] ? 'Deployment started.' : 'Deployment queued.');
            $this->dispatch('reload-page', delay: 300);

            return;
        }

        $deployment = $service->deploy($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Deployment completed.'
            : 'Deployment failed. Check logs below.');
        $this->dispatch('reload-page', delay: 800);
    }

    public function forceDeploy(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked()) {
            return;
        }

        if ($this->deploymentInProgress()) {
            $this->dispatch('notify', message: 'A deployment is already running for this project.');
            return;
        }

        app(DeploymentQueueService::class)->cancelQueuedGroup($this->project, 'force_deploy');
        if ($this->queueEnabled()) {
            $result = app(DeploymentQueueService::class)->enqueueForImmediateProcessing($this->project, 'force_deploy', ['reason' => 'manual_force_deploy'], Auth::user());
            $this->dispatch('notify', message: $result['started'] ? 'Force deployment started.' : 'Force deployment queued.');
            $this->dispatch('reload-page', delay: 300);

            return;
        }

        $deployment = $service->deploy($this->project, Auth::user(), true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Force deployment completed.'
            : 'Force deployment failed. Check logs below.');
        $this->dispatch('reload-page', delay: 800);
    }

    public function deployAnyway(DeploymentService $service): void
    {
        if ($this->deploymentInProgress()) {
            $this->dispatch('notify', message: 'A deployment is already running for this project.');
            return;
        }

        app(DeploymentQueueService::class)->cancelQueuedGroup($this->project, 'deploy');
        if ($this->queueEnabled()) {
            $result = app(DeploymentQueueService::class)->enqueueForImmediateProcessing($this->project, 'deploy', ['reason' => 'manual_staged_deploy', 'ignore_permissions_lock' => true], Auth::user());
            $this->dispatch('notify', message: $result['started'] ? 'Deployment started with staged install.' : 'Deployment queued with staged install.');
            $this->dispatch('reload-page', delay: 300);

            return;
        }

        $deployment = $service->deploy($this->project, Auth::user(), false, true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Deployment completed (staged install).' 
            : 'Deployment failed. Check logs below.');
        $this->dispatch('reload-page', delay: 800);
    }

    public function rollback(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked()) {
            return;
        }

        if ($this->deploymentInProgress()) {
            $this->dispatch('notify', message: 'A deployment is already running for this project.');
            return;
        }

        app(DeploymentQueueService::class)->cancelQueuedGroup($this->project, 'rollback');
        if ($this->queueEnabled()) {
            $result = app(DeploymentQueueService::class)->enqueueForImmediateProcessing($this->project, 'rollback', ['reason' => 'manual_rollback'], Auth::user());
            $this->dispatch('notify', message: $result['started'] ? 'Rollback started.' : 'Rollback queued.');
            $this->dispatch('reload-page', delay: 300);

            return;
        }

        $deployment = $service->rollback($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Rollback completed.'
            : 'Rollback failed. Check logs below.');
        $this->dispatch('reload-page', delay: 800);
    }

    public function forceStopDeployment(DeploymentService $service): void
    {
        $runningTask = $this->currentRunningTask();
        $taskLabel = $runningTask['label'] ?? 'Task';
        $count = $service->forceStop($this->project, Auth::user(), $taskLabel.' manually stopped.');

        $cancelled = DeploymentQueueItem::query()
            ->where('project_id', $this->project->id)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'cancelled',
                'finished_at' => now(),
            ]);

        if ($count === 0 && $cancelled === 0) {
            $this->dispatch('notify', message: 'No running tasks were found.');
        } else {
            $this->dispatch('notify', message: "{$taskLabel} stopped and queue cleared.");
        }

        $this->project->refresh();
        $this->dispatch('reload-page', delay: 800);
    }

    private function queueEnabled(): bool
    {
        return (bool) config('gitmanager.deploy_queue.enabled', true);
    }

    private function shouldAutoCheckHealth(Project $project): bool
    {
        return (bool) (
            $project->health_url
            || $project->site_url
            || $project->last_deployed_at
            || $project->last_deployed_hash
        );
    }

    private function deploymentInProgress(): bool
    {
        $projectId = $this->project->id;

        app(DeploymentService::class)->releaseStaleRunningDeployments();
        app(DeploymentQueueService::class)->releaseStaleRunning();

        $runningDeployment = Deployment::query()
            ->where('project_id', $projectId)
            ->where('status', 'running')
            ->exists();

        if ($runningDeployment) {
            return true;
        }

        return DeploymentQueueItem::query()
            ->where('project_id', $projectId)
            ->where('status', 'running')
            ->exists();
    }

    /**
     * @return array{action: string, label: string}|null
     */
    private function currentRunningTask(): ?array
    {
        $projectId = $this->project->id;

        app(DeploymentService::class)->releaseStaleRunningDeployments();
        app(DeploymentQueueService::class)->releaseStaleRunning();

        $runningDeployment = Deployment::query()
            ->where('project_id', $projectId)
            ->where('status', 'running')
            ->orderByDesc('started_at')
            ->first(['action', 'started_at']);

        $runningQueueItem = DeploymentQueueItem::query()
            ->where('project_id', $projectId)
            ->where('status', 'running')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first(['action', 'started_at']);

        if (! $runningDeployment && ! $runningQueueItem) {
            return null;
        }

        $action = $runningDeployment?->action;
        if (! $action) {
            $action = $runningQueueItem?->action;
        }

        if (! is_string($action) || trim($action) === '') {
            return null;
        }

        return [
            'action' => $action,
            'label' => $this->taskLabelForAction($action),
        ];
    }

    private function taskLabelForAction(string $action): string
    {
        return match ($action) {
            'deploy' => 'Deployment',
            'force_deploy' => 'Force Deployment',
            'rollback' => 'Rollback',
            'composer_install' => 'Composer Install',
            'composer_update' => 'Composer Update',
            'composer_audit' => 'Composer Audit',
            'npm_install' => 'Npm Install',
            'npm_update' => 'Npm Update',
            'npm_audit' => 'Npm Audit',
            'npm_audit_fix' => 'Npm Audit Fix',
            'npm_audit_fix_force' => 'Npm Audit Fix (Force)',
            'audit_project' => 'Project Audit',
            'preview_build' => 'Preview Build',
            'app_clear_cache' => 'App Clear Cache',
            'laravel_migrate' => 'Laravel Migrate',
            default => ucfirst(str_replace('_', ' ', $action)),
        };
    }

    /**
     * @return array<int, string>
     */
    private function deploymentColumns(): array
    {
        return [
            'deployments.id',
            'deployments.project_id',
            'deployments.triggered_by',
            'deployments.action',
            'deployments.status',
            'deployments.from_hash',
            'deployments.to_hash',
            'deployments.started_at',
            'deployments.finished_at',
        ];
    }

    private function logPreviewLimit(): int
    {
        return 120000;
    }

    private function logPreviewSql(string $column, int $limit): string
    {
        return "CASE WHEN length({$column}) > {$limit} THEN substr({$column}, length({$column}) - {$limit} + 1) ELSE {$column} END";
    }

    private function blockIfPermissionsLocked(string $context = 'deployments'): bool
    {
        if (! $this->project->permissions_locked || $this->project->ftp_enabled || $this->project->ssh_enabled) {
            return false;
        }

        $this->dispatch('notify', message: 'Permissions need fixing before running '.$context.'.');
        return true;
    }

    private function envTabEnabled(): bool
    {
        $path = trim((string) ($this->project->local_path ?? ''));
        if ($path === '' || ! is_dir($path)) {
            return false;
        }

        $root = $path;
        if (($this->project->project_type ?? '') === 'laravel') {
            $root = $this->findLaravelRoot($path) ?? $path;
        }

        $envPath = $root.DIRECTORY_SEPARATOR.'.env';
        $examplePath = $root.DIRECTORY_SEPARATOR.'.env.example';

        return is_file($envPath) || is_file($examplePath);
    }

    private function findLaravelRoot(string $path): ?string
    {
        $cursor = $path;

        while (true) {
            if (is_file($cursor.DIRECTORY_SEPARATOR.'artisan')) {
                return $cursor;
            }

            $parent = dirname($cursor);
            if (! $parent || $parent === $cursor) {
                break;
            }

            $cursor = $parent;
        }

        return null;
    }

    private function markUpdateCheckAttempt(): void
    {
        $this->project->last_checked_at = now();
        $this->project->updates_checked_at = now();
        $this->project->save();
        $this->project->refresh();
    }

    /**
     * @param array<string, array<string, mixed>> $results
     */
    private function dispatchAuditToast(array $results): void
    {
        $summary = $this->summarizeAuditResults($results);
        $remaining = $summary['remaining'];

        if ($remaining > 0) {
            $label = $remaining === 1 ? '1 vulnerability' : "{$remaining} vulnerabilities";
            $this->dispatch('notify', message: "Vulnerabilities found ({$label} remaining). Open the Security tab to resolve them.", type: 'error');
            return;
        }

        if ($summary['failed'] > 0) {
            $this->dispatch('notify', message: 'Audit completed with errors. Check the logs for details.', type: 'warning');
            return;
        }

        $this->dispatch('notify', message: 'Audit complete. No vulnerabilities found.', type: 'success');
    }

    /**
     * @param array<string, array<string, mixed>> $results
     * @return array{remaining: int, failed: int}
     */
    private function summarizeAuditResults(array $results): array
    {
        $remaining = 0;
        $failed = 0;

        foreach ($results as $result) {
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

        return [
            'remaining' => $remaining,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function composerActions(): array
    {
        return [
            'composer_install',
            'composer_update',
            'composer_audit',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function npmActions(): array
    {
        return [
            'npm_install',
            'npm_update',
            'npm_audit',
            'npm_audit_fix',
            'npm_audit_fix_force',
        ];
    }

    public function checkUpdates(DeploymentService $service): void
    {
        try {
            $hasUpdates = $service->checkForUpdates($this->project);
        } catch (\Throwable $exception) {
            $this->markUpdateCheckAttempt();
            $this->dispatch('notify', message: 'Update check failed: '.$exception->getMessage());
            return;
        }
        $this->project->refresh();
        if ($hasUpdates && $this->queueEnabled()) {
            $result = app(DeploymentQueueService::class)->enqueueForImmediateProcessing($this->project, 'deploy', ['reason' => 'manual_update_check'], Auth::user());
            $this->dispatch('notify', message: $result['started'] ? 'Updates available. Deployment started.' : 'Updates available. Deployment queued.');
            return;
        }

        $this->dispatch('notify', message: $hasUpdates
            ? 'Updates available for this project.'
            : 'No updates detected.');
    }

    public function auditProject(AuditService $audit): void
    {
        if (! $this->isEnterpriseEdition()) {
            $this->dispatch('notify', message: 'Automatic project audits are available in Enterprise Edition.', type: 'warning');
            $this->dispatch('gwm-open-enterprise-modal', feature: 'Automatic Project & Container Audits');
            return;
        }

        if ($this->blockIfPermissionsLocked('audit checks')) {
            return;
        }

        if ($this->queueEnabled()) {
            $result = app(DeploymentQueueService::class)->enqueueForImmediateProcessing($this->project, 'audit_project', [
                'auto_fix' => true,
                'send_email' => true,
                'source' => 'manual_project_audit',
            ], Auth::user());

            $this->dispatch('notify', message: $result['existing']
                ? 'Project audit is already queued.'
                : ($result['started'] ? 'Project audit started.' : 'Project audit queued.'));

            return;
        }

        $payload = $audit->auditProject($this->project, Auth::user(), true, true);
        $this->project->refresh();
        $this->dispatchAuditToast($payload['results'] ?? []);
    }

    public function checkHealth(DeploymentService $service): void
    {
        $status = $service->checkHealth($this->project, true);
        $this->project->refresh();
        $this->dispatch('notify', message: match ($status) {
            'ok' => 'Health check passed.',
            default => 'Health check unavailable.',
        });
    }

    public function fixPermissions(DeploymentService $service): void
    {
        $deployment = $service->fixPermissions($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Permissions updated.'
            : 'Permission fix failed. Check logs below.');
    }

    public function createPreview(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('preview builds')) {
            return;
        }

        $commit = trim($this->previewCommit);
        $deployment = $service->previewBuild($this->project, Auth::user(), $commit);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Preview build created.'
            : 'Preview build failed. Check logs below.');
    }

    public function createPreviewForCommit(string $commit, DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('preview builds')) {
            return;
        }

        $deployment = $service->previewBuild($this->project, Auth::user(), $commit);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Preview build created.'
            : 'Preview build failed. Check logs below.');
    }

    public function deleteProject(): void
    {
        $project = $this->project;
        $this->authorize('delete', $project);

        $project->delete();

        $this->dispatch('notify', message: 'Project deleted.');
        $this->redirectRoute('projects.index', navigate: false);
    }

    public function deleteProjectFiles(): void
    {
        $project = $this->project;
        $this->authorize('delete', $project);

        $path = realpath($project->local_path);
        $appPath = realpath(base_path());

        if (! $path) {
            $project->delete();
            $this->dispatch('notify', message: 'Project deleted.');
            $this->redirectRoute('projects.index', navigate: false);
            return;
        }

        if ($appPath && $path === $appPath) {
            $this->dispatch('notify', message: 'Refusing to delete the Git Web Manager directory.');
            return;
        }

        if ($path === dirname($path)) {
            $this->dispatch('notify', message: 'Refusing to delete a root directory.');
            return;
        }

        \Illuminate\Support\Facades\File::deleteDirectory($path);

        $project->delete();
        $this->dispatch('notify', message: 'Project and files deleted.');
        $this->redirectRoute('projects.index', navigate: false);
    }

    private function isEnterpriseEdition(): bool
    {
        return app(EditionService::class)->current() === EditionService::ENTERPRISE;
    }

}
