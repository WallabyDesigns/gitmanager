<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Models\SecurityAlert;
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
        $securityOpenCount = SecurityAlert::query()
            ->where('project_id', $this->project->id)
            ->where('state', 'open')
            ->count();

        return view('livewire.projects.show', [
            'deployments' => (clone $deployments)->paginate(20),
            'recentDebug' => (clone $deployments)->take(3)->get(),
            'commits' => $commits,
            'activeCommit' => $activeCommit,
            'lastSuccessfulDeploy' => $lastSuccessfulDeploy,
            'rollbackAvailable' => $rollbackAvailable,
            'envTabEnabled' => $this->envTabEnabled(),
            'securityOpenCount' => $securityOpenCount,
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.show-header', [
                'project' => $this->project,
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
        $deployment = $service->rollback($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Rollback completed.'
            : 'Rollback failed. Check logs below.');
        $this->dispatch('reload-page', delay: 800);
    }

    private function queueEnabled(): bool
    {
        return (bool) config('gitmanager.deploy_queue.enabled', true);
    }

    private function shouldAutoCheckHealth(Project $project): bool
    {
        return (bool) ($project->last_deployed_at || $project->last_deployed_hash);
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

}
