<?php

namespace App\Livewire\Projects;

use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use App\Services\ProjectSeedService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class EnvEditor extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public string $envContent = '';

    public bool $envExists = false;

    public bool $envExampleExists = false;

    public ?string $envExampleLabel = null;

    public ?string $envPath = null;

    public ?string $envStatus = null;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->loadEnv();
    }

    public function render()
    {
        return view('livewire.projects.env-editor');
    }

    public function reloadEnv(): void
    {
        $this->loadEnv();
        $this->dispatch('notify', message: 'Environment loaded.');
    }

    public function createFromExample(): void
    {
        $shouldAutoDeploy = $this->shouldAutoDeployAfterEnvSave();
        [$root, $error] = $this->resolveProjectRoot();
        if ($error) {
            $this->dispatch('notify', message: $error);

            return;
        }

        $example = $this->resolveEnvExamplePath($root);
        $envPath = $root.DIRECTORY_SEPARATOR.'.env';
        if (! $example || ! is_file($example)) {
            $this->dispatch('notify', message: 'No .env.example or .env.sample found.');

            return;
        }

        if (is_file($envPath)) {
            $this->dispatch('notify', message: '.env already exists.');
            $this->loadEnv();

            return;
        }

        if (! @copy($example, $envPath)) {
            $this->dispatch('notify', message: 'Unable to create .env from .env.example.');

            return;
        }

        $contents = @file_get_contents($envPath);
        if ($contents !== false) {
            app(ProjectSeedService::class)->store($this->project, '.env', rtrim($contents)."\n");
        }

        $this->loadEnv();
        $this->dispatch('notify', message: '.env created from .env.example.');
        $this->dispatch('env-updated');
        $this->maybeTriggerDeployAfterEnvSave($shouldAutoDeploy);
    }

    public function save(): void
    {
        $shouldAutoDeploy = $this->shouldAutoDeployAfterEnvSave();
        [$root, $error] = $this->resolveProjectRoot();
        if ($error) {
            $this->dispatch('notify', message: $error);

            return;
        }

        $envPath = $root.DIRECTORY_SEPARATOR.'.env';
        if (is_file($envPath) && ! is_writable($envPath)) {
            $this->dispatch('notify', message: '.env is not writable.');

            return;
        }

        if (! is_file($envPath) && ! is_writable($root)) {
            $this->dispatch('notify', message: 'Project directory is not writable.');

            return;
        }

        if (@file_put_contents($envPath, $this->envContent) === false) {
            $this->dispatch('notify', message: 'Failed to save .env.');

            return;
        }

        app(ProjectSeedService::class)->store($this->project, '.env', rtrim($this->envContent)."\n");

        $this->loadEnv();
        $this->dispatch('notify', message: '.env saved.');
        $this->dispatch('env-updated');
        $this->maybeTriggerDeployAfterEnvSave($shouldAutoDeploy);
    }

    private function loadEnv(): void
    {
        [$root, $error] = $this->resolveProjectRoot();
        if ($error) {
            $this->envStatus = $error;
            $this->envContent = '';
            $this->envExists = false;
            $this->envExampleExists = false;
            $this->envExampleLabel = null;
            $this->envPath = null;

            return;
        }

        $this->envPath = $root.DIRECTORY_SEPARATOR.'.env';
        $examplePath = $this->resolveEnvExamplePath($root);
        $this->envExists = is_file($this->envPath);
        $this->envExampleExists = $examplePath !== null && is_file($examplePath);
        $this->envExampleLabel = $this->envExampleExists ? basename($examplePath) : null;

        if ($this->envExists) {
            $contents = @file_get_contents($this->envPath);
            $this->envContent = $contents === false ? '' : $contents;
            $this->envStatus = null;

            return;
        }

        $this->envStatus = 'No .env file found yet.';
        $this->envContent = '';
    }

    private function resolveEnvExamplePath(string $root): ?string
    {
        $candidate = $root.DIRECTORY_SEPARATOR.'.env.example';
        if (is_file($candidate)) {
            return $candidate;
        }

        $candidate = $root.DIRECTORY_SEPARATOR.'.env.sample';
        if (is_file($candidate)) {
            return $candidate;
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveProjectRoot(): array
    {
        $path = trim((string) $this->project->local_path);
        if ($path === '') {
            return [null, 'Project path not configured.'];
        }

        if (! is_dir($path)) {
            return [null, 'Project path not found: '.$path];
        }

        if (($this->project->project_type ?? '') === 'laravel') {
            $laravelRoot = $this->findLaravelRoot($path);
            if ($laravelRoot) {
                $path = $laravelRoot;
            }
        }

        return [$path, null];
    }

    private function maybeTriggerDeployAfterEnvSave(bool $shouldAutoDeploy): void
    {
        if (! $shouldAutoDeploy) {
            return;
        }

        if ($this->project->permissions_locked && ! $this->project->ftp_enabled && ! $this->project->ssh_enabled) {
            $this->dispatch('notify', message: '.env saved, but permissions are locked. Fix permissions before deploying.');

            return;
        }

        if ($this->deploymentInProgress()) {
            $this->dispatch('notify', message: '.env saved, but a deployment is already running.');

            return;
        }

        app(DeploymentQueueService::class)->cancelQueuedGroup($this->project, 'deploy');
        if ((bool) config('gitmanager.deploy_queue.enabled', true)) {
            $result = app(DeploymentQueueService::class)->enqueueForImmediateProcessing($this->project, 'deploy', ['reason' => 'env_saved'], Auth::user());
            $this->dispatch('notify', message: $result['started'] ? '.env saved. Deployment started.' : '.env saved. Deployment queued.');

            return;
        }

        $deployment = app(DeploymentService::class)->deploy($this->project, Auth::user());
        $this->project->refresh();

        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Deployment completed after .env update.'
            : 'Deployment failed after .env update. Check logs below.');
    }

    private function shouldAutoDeployAfterEnvSave(): bool
    {
        $message = $this->project->last_error_message;
        if (is_string($message) && $this->isEnvBlockingMessage($message)) {
            return true;
        }

        $healthMessage = $this->project->health_issue_message;
        if (is_string($healthMessage) && $this->isEnvBlockingMessage($healthMessage)) {
            return true;
        }

        return false;
    }

    private function isEnvBlockingMessage(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, '.env')
            || str_contains($message, 'env file')
            || str_contains($message, 'update .env');
    }

    private function deploymentInProgress(): bool
    {
        $projectId = $this->project->id;

        app(DeploymentService::class)->releaseStaleRunningDeployments();
        app(DeploymentQueueService::class)->releaseStaleRunning();

        if (Deployment::query()
            ->where('project_id', $projectId)
            ->where('status', 'running')
            ->exists()) {
            return true;
        }

        return DeploymentQueueItem::query()
            ->where('project_id', $projectId)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
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
