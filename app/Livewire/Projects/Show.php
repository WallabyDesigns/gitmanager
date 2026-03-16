<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\DeploymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Show extends Component
{
    public Project $project;
    public string $customCommand = '';
    public string $previewCommit = '';

    public function mount(Project $project): void
    {
        abort_unless($project->user_id === Auth::id(), 403);
        $this->project = $project;
    }

    public function render()
    {
        $dependencyActions = [
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

        $deployments = $this->project->deployments()->orderByDesc('started_at');
        $dependencyLogs = (clone $deployments)->whereIn('action', $dependencyActions)->take(10)->get();

        $service = app(DeploymentService::class);
        $commits = $service->getRecentCommits($this->project, 3);
        $currentHead = $service->getCurrentHead($this->project);
        $activeCommit = $currentHead ?: $this->project->last_deployed_hash;

        return view('livewire.projects.show', [
            'deployments' => (clone $deployments)->take(20)->get(),
            'recentDebug' => (clone $deployments)->take(3)->get(),
            'dependencyLogs' => $dependencyLogs,
            'latestDependencyLog' => $dependencyLogs->first(),
            'commits' => $commits,
            'activeCommit' => $activeCommit,
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.show-header', [
                'project' => $this->project,
            ]),
        ]);
    }

    public function deploy(DeploymentService $service): void
    {
        $deployment = $service->deploy($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Deployment completed.'
            : 'Deployment failed. Check logs below.');
    }

    public function forceDeploy(DeploymentService $service): void
    {
        $deployment = $service->deploy($this->project, Auth::user(), true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Force deployment completed.'
            : 'Force deployment failed. Check logs below.');
    }

    public function rollback(DeploymentService $service): void
    {
        $deployment = $service->rollback($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Rollback completed.'
            : 'Rollback failed. Check logs below.');
    }

    public function checkUpdates(DeploymentService $service): void
    {
        $hasUpdates = $service->checkForUpdates($this->project);
        $this->project->refresh();
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
            'fail' => 'Health check failed.',
            default => 'Health check not configured.',
        });
    }

    public function updateDependencies(DeploymentService $service): void
    {
        $deployment = $service->updateDependencies($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Dependency update completed.'
            : 'Dependency update failed. Check logs below.');
    }

    public function composerInstall(DeploymentService $service): void
    {
        $deployment = $service->composerInstall($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer install completed.'
            : 'Composer install failed. Check logs below.');
    }

    public function composerUpdate(DeploymentService $service): void
    {
        $deployment = $service->composerUpdate($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer update completed.'
            : 'Composer update failed. Check logs below.');
    }

    public function composerAudit(DeploymentService $service): void
    {
        $deployment = $service->composerAudit($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer audit completed.'
            : 'Composer audit failed. Check logs below.');
    }

    public function appClearCache(DeploymentService $service): void
    {
        $deployment = $service->appClearCache($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'app:clear-cache completed.'
            : 'app:clear-cache failed. Check logs below.');
    }

    public function npmInstall(DeploymentService $service): void
    {
        $deployment = $service->npmInstall($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm install completed.'
            : 'Npm install failed. Check logs below.');
    }

    public function npmUpdate(DeploymentService $service): void
    {
        $deployment = $service->npmUpdate($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm update completed.'
            : 'Npm update failed. Check logs below.');
    }

    public function npmAuditFix(DeploymentService $service): void
    {
        $deployment = $service->npmAuditFix($this->project, Auth::user(), false);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm audit fix completed.'
            : 'Npm audit fix failed. Check logs below.');
    }

    public function npmAuditFixForce(DeploymentService $service): void
    {
        $deployment = $service->npmAuditFix($this->project, Auth::user(), true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm audit fix (force) completed.'
            : 'Npm audit fix (force) failed. Check logs below.');
    }

    public function runCustomCommand(DeploymentService $service): void
    {
        $deployment = $service->runCustomCommand($this->project, Auth::user(), $this->customCommand);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Command completed.'
            : 'Command failed. Check logs below.');
    }

    public function createPreview(DeploymentService $service): void
    {
        $commit = trim($this->previewCommit);
        $deployment = $service->previewBuild($this->project, Auth::user(), $commit);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Preview build created.'
            : 'Preview build failed. Check logs below.');
    }

    public function createPreviewForCommit(string $commit, DeploymentService $service): void
    {
        $deployment = $service->previewBuild($this->project, Auth::user(), $commit);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Preview build created.'
            : 'Preview build failed. Check logs below.');
    }

    public function deleteProject(): void
    {
        $project = $this->project;
        abort_unless($project->user_id === Auth::id(), 403);

        $project->delete();

        $this->dispatch('notify', message: 'Project deleted.');
        $this->redirectRoute('projects.index', navigate: true);
    }

    public function deleteProjectFiles(): void
    {
        $project = $this->project;
        abort_unless($project->user_id === Auth::id(), 403);

        $path = realpath($project->local_path);
        $appPath = realpath(base_path());

        if (! $path) {
            $project->delete();
            $this->dispatch('notify', message: 'Project deleted.');
            $this->redirectRoute('projects.index', navigate: true);
            return;
        }

        if ($appPath && $path === $appPath) {
            $this->dispatch('notify', message: 'Refusing to delete the Git Project Manager directory.');
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
