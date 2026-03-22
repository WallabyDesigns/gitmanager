<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Services\DeploymentService;
use App\Services\DeploymentQueueService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public string $projectsTab = 'list';

    public Project $project;
    public string $customCommand = '';
    public string $previewCommit = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    public function render()
    {
        $dependencyActions = $this->dependencyActions();

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
        $dependencyLogs = (clone $deployments)->whereIn('action', $dependencyActions)->take(10)->get();

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

        return view('livewire.projects.show', [
            'deployments' => (clone $deployments)->take(20)->get(),
            'recentDebug' => (clone $deployments)->take(3)->get(),
            'dependencyLogs' => $dependencyLogs,
            'latestDependencyLog' => $dependencyLogs->first(),
            'commits' => $commits,
            'activeCommit' => $activeCommit,
            'lastSuccessfulDeploy' => $lastSuccessfulDeploy,
            'rollbackAvailable' => $rollbackAvailable,
            'hasComposer' => $service->hasComposer($this->project),
            'hasNpm' => $service->hasNpm($this->project),
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.show-header', [
                'project' => $this->project,
            ]),
        ]);
    }

    public function refreshHealthStatus(DeploymentService $service): void
    {
        if (! $this->project->health_checked_at || $this->project->health_checked_at->lt(now()->subMinute())) {
            $service->checkHealth($this->project);
            $this->project->refresh();
        }

        $updatesCheckedAt = $this->project->updates_checked_at;
        if (is_string($updatesCheckedAt)) {
            $updatesCheckedAt = Carbon::parse($updatesCheckedAt);
        }

        if (! $updatesCheckedAt || $updatesCheckedAt->lt(now()->subMinutes(5))) {
            $wasAvailable = (bool) $this->project->updates_available;
            $hasUpdates = $service->checkForUpdates($this->project);
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
        $deployment = $service->deploy($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Deployment completed.'
            : 'Deployment failed. Check logs below.');
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
        $deployment = $service->deploy($this->project, Auth::user(), true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Force deployment completed.'
            : 'Force deployment failed. Check logs below.');
    }

    public function deployAnyway(DeploymentService $service): void
    {
        if ($this->deploymentInProgress()) {
            $this->dispatch('notify', message: 'A deployment is already running for this project.');
            return;
        }

        app(DeploymentQueueService::class)->cancelQueuedGroup($this->project, 'deploy');
        $deployment = $service->deploy($this->project, Auth::user(), false, true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Deployment completed (staged install).' 
            : 'Deployment failed. Check logs below.');
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
        $deployment = $service->rollback($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Rollback completed.'
            : 'Rollback failed. Check logs below.');
    }

    private function queueEnabled(): bool
    {
        return (bool) config('gitmanager.deploy_queue.enabled', true);
    }

    private function deploymentInProgress(): bool
    {
        $projectId = $this->project->id;

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
        if (! $this->project->permissions_locked) {
            return false;
        }

        $this->dispatch('notify', message: 'Permissions need fixing before running '.$context.'.');
        return true;
    }

    public function checkUpdates(DeploymentService $service): void
    {
        $hasUpdates = $service->checkForUpdates($this->project);
        $this->project->refresh();
        if ($hasUpdates && $this->queueEnabled()) {
            app(DeploymentQueueService::class)->enqueue($this->project, 'deploy', ['reason' => 'manual_update_check'], Auth::user());
            $this->dispatch('notify', message: 'Updates available. Deployment queued.');
            return;
        }

        $this->dispatch('notify', message: $hasUpdates
            ? 'Updates available for this project.'
            : 'No updates detected.');
    }

    public function checkHealth(DeploymentService $service): void
    {
        $status = $service->checkHealth($this->project);
        $this->project->refresh();
        $this->dispatch('notify', message: match ($status) {
            'ok' => 'Health check passed.',
            default => 'Health check unavailable.',
        });
    }

    public function updateDependencies(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('dependency actions')) {
            return;
        }

        $deployment = $service->updateDependencies($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Dependency update completed.'
            : 'Dependency update failed. Check logs below.');
    }

    public function clearLatestDependencyOutput(): void
    {
        $latest = $this->project
            ->deployments()
            ->whereIn('action', $this->dependencyActions())
            ->orderByDesc('started_at')
            ->first();

        if ($latest && $latest->output_log) {
            $latest->output_log = null;
            $latest->save();
        }

        $this->dispatch('notify', message: 'Latest output cleared.');
    }

    public function composerInstall(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('composer install')) {
            return;
        }

        $deployment = $service->composerInstall($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer install completed.'
            : 'Composer install failed. Check logs below.');
    }

    public function composerUpdate(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('composer update')) {
            return;
        }

        $deployment = $service->composerUpdate($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer update completed.'
            : 'Composer update failed. Check logs below.');
    }

    public function composerAudit(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('composer audit')) {
            return;
        }

        $deployment = $service->composerAudit($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer audit completed.'
            : 'Composer audit failed. Check logs below.');
    }

    public function appClearCache(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('cache clearing')) {
            return;
        }

        $deployment = $service->appClearCache($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'app:clear-cache completed.'
            : 'app:clear-cache failed. Check logs below.');
    }

    public function npmInstall(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('npm install')) {
            return;
        }

        $deployment = $service->npmInstall($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm install completed.'
            : 'Npm install failed. Check logs below.');
    }

    public function npmUpdate(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('npm update')) {
            return;
        }

        $deployment = $service->npmUpdate($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm update completed.'
            : 'Npm update failed. Check logs below.');
    }

    public function npmAuditFix(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('npm audit fix')) {
            return;
        }

        $deployment = $service->npmAuditFix($this->project, Auth::user(), false);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm audit fix completed.'
            : 'Npm audit fix failed. Check logs below.');
    }

    public function npmAuditFixForce(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('npm audit fix')) {
            return;
        }

        $deployment = $service->npmAuditFix($this->project, Auth::user(), true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm audit fix (force) completed.'
            : 'Npm audit fix (force) failed. Check logs below.');
    }

    public function runCustomCommand(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('custom commands')) {
            return;
        }

        $deployment = $service->runCustomCommand($this->project, Auth::user(), $this->customCommand);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Command completed.'
            : 'Command failed. Check logs below.');
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
        $this->redirectRoute('projects.index', navigate: true);
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
            $this->redirectRoute('projects.index', navigate: true);
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
        $this->redirectRoute('projects.index', navigate: true);
    }

    private function dependencyActions(): array
    {
        return [
            'dependency_update',
            'composer_install',
            'composer_update',
            'composer_audit',
            'app_clear_cache',
            'npm_install',
            'npm_update',
            'npm_audit_fix',
            'npm_audit_fix_force',
            'custom_command',
        ];
    }
}
