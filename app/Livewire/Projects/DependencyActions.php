<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\DeploymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DependencyActions extends Component
{
    public Project $project;
    public string $customCommand = '';
    public bool $hasComposer = false;
    public bool $hasNpm = false;
    public bool $hasLaravel = false;

    protected $listeners = [
        'env-updated' => '$refresh',
    ];

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    public function render()
    {
        $dependencyActions = $this->dependencyActions();
        [$this->hasComposer, $this->hasNpm, $this->hasLaravel] = $this->detectProjectFeatures();

        $dependencyLogs = $this->project->deployments()
            ->whereIn('action', $dependencyActions)
            ->orderByDesc('started_at')
            ->take(10)
            ->get();

        return view('livewire.projects.dependency-actions', [
            'dependencyLogs' => $dependencyLogs,
            'latestDependencyLog' => $dependencyLogs->first(),
            'hasComposer' => $this->hasComposer,
            'hasNpm' => $this->hasNpm,
            'hasLaravel' => $this->hasLaravel,
            'permissionsLocked' => (bool) $this->project->permissions_locked,
        ]);
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

    public function laravelMigrate(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('migrations')) {
            return;
        }

        $deployment = $service->laravelMigrate($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Migrations completed.'
            : 'Migrations failed. Check logs below.');
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

    private function blockIfPermissionsLocked(string $context = 'deployments'): bool
    {
        if (! $this->project->permissions_locked) {
            return false;
        }

        $this->dispatch('notify', message: 'Permissions need fixing before running '.$context.'.');
        return true;
    }

    private function dependencyActions(): array
    {
        return [
            'dependency_update',
            'composer_install',
            'composer_update',
            'composer_audit',
            'app_clear_cache',
            'laravel_migrate',
            'npm_install',
            'npm_update',
            'npm_audit_fix',
            'npm_audit_fix_force',
            'custom_command',
        ];
    }

    /**
     * @return array{0: bool, 1: bool, 2: bool}
     */
    private function detectProjectFeatures(): array
    {
        $path = trim((string) ($this->project->local_path ?? ''));
        if ($path === '' || ! is_dir($path)) {
            return [false, false, false];
        }

        $laravelRoot = $this->findLaravelRoot($path);
        $root = $laravelRoot ?? $path;

        $hasComposer = is_file($root.DIRECTORY_SEPARATOR.'composer.json');
        $hasNpm = is_file($root.DIRECTORY_SEPARATOR.'package.json');
        $hasLaravel = $laravelRoot !== null;

        return [$hasComposer, $hasNpm, $hasLaravel];
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
}
