<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class EnvEditor extends Component
{
    use AuthorizesRequests;

    public Project $project;
    public string $envContent = '';
    public bool $envExists = false;
    public bool $envExampleExists = false;
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
        [$root, $error] = $this->resolveProjectRoot();
        if ($error) {
            $this->dispatch('notify', message: $error);
            return;
        }

        $example = $root.DIRECTORY_SEPARATOR.'.env.example';
        $envPath = $root.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($example)) {
            $this->dispatch('notify', message: '.env.example not found.');
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

        $this->loadEnv();
        $this->dispatch('notify', message: '.env created from .env.example.');
    }

    public function save(): void
    {
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

        $this->loadEnv();
        $this->dispatch('notify', message: '.env saved.');
    }

    private function loadEnv(): void
    {
        [$root, $error] = $this->resolveProjectRoot();
        if ($error) {
            $this->envStatus = $error;
            $this->envContent = '';
            $this->envExists = false;
            $this->envExampleExists = false;
            $this->envPath = null;
            return;
        }

        $this->envPath = $root.DIRECTORY_SEPARATOR.'.env';
        $examplePath = $root.DIRECTORY_SEPARATOR.'.env.example';
        $this->envExists = is_file($this->envPath);
        $this->envExampleExists = is_file($examplePath);

        if ($this->envExists) {
            $contents = @file_get_contents($this->envPath);
            $this->envContent = $contents === false ? '' : $contents;
            $this->envStatus = null;

            return;
        }

        $this->envStatus = 'No .env file found yet.';
        $this->envContent = '';
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
