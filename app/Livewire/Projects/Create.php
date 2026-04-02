<?php

namespace App\Livewire\Projects;

use App\Services\RepositoryBootstrapper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public string $projectsTab = 'create';

    public array $form = [];
    public int $step = 1;
    public bool $checkPermissions = false;
    public ?string $permissionStatus = null;
    public ?string $permissionMessage = null;
    public ?string $permissionParent = null;
    public ?string $ftpTestStatus = null;
    public ?string $ftpTestMessage = null;

    /**
     * @var array<int, array{value: string, label: string, description: string}>
     */
    public array $projectTypes = [
        [
            'value' => 'laravel',
            'label' => 'Laravel',
            'description' => 'Composer, npm, Vite build, and artisan tests.',
        ],
        [
            'value' => 'node',
            'label' => 'Node',
            'description' => 'npm install/build/test workflow.',
        ],
        [
            'value' => 'static',
            'label' => 'Static',
            'description' => 'Static or frontend-only build output.',
        ],
        [
            'value' => 'custom',
            'label' => 'Custom',
            'description' => 'Manually configure build/deploy settings.',
        ],
    ];

    public function mount(): void
    {
        $this->form = [
            'name' => '',
            'project_type' => 'laravel',
            'repo_url' => '',
            'site_url' => '',
            'local_path' => '',
            'default_branch' => 'main',
            'auto_deploy' => true,
            'health_url' => '/up',
            'run_composer_install' => true,
            'run_npm_install' => true,
            'run_build_command' => true,
            'build_command' => 'npm run build',
            'run_test_command' => true,
            'test_command' => 'php artisan test',
            'allow_dependency_updates' => true,
            'exclude_paths' => '',
            'ftp_enabled' => false,
            'ftp_account_id' => null,
            'ftp_root_path' => '',
            'ssh_enabled' => false,
            'ssh_port' => 22,
            'ssh_root_path' => '',
            'ssh_commands' => '',
        ];

        $this->applyProjectTypeDefaults('laravel');
    }

    public function render()
    {
        return view('livewire.projects.create', [
            'ftpAccounts' => \App\Models\FtpAccount::query()->orderBy('name')->get(),
        ])
            ->layout('layouts.app', [
                'title' => 'Create Project',
                'header' => view('livewire.projects.partials.create-header'),
            ]);
    }

    public function updatedFormProjectType(string $value): void
    {
        $this->applyProjectTypeDefaults($value);
    }

    public function updatedCheckPermissions(bool $value): void
    {
        if (! $value) {
            $this->clearPermissionCheck();
            return;
        }

        $this->checkPathPermissions();
    }

    public function updatedFormLocalPath(string $value): void
    {
        if (! $this->checkPermissions) {
            return;
        }

        $this->checkPathPermissions();
    }

    public function updatedFormFtpAccountId(): void
    {
        if (! ($this->form['ftp_enabled'] ?? false)) {
            return;
        }

        $this->testFtpConnection();
    }

    public function updatedFormFtpEnabled(bool $value): void
    {
        if (! $value) {
            $this->ftpTestStatus = null;
            $this->ftpTestMessage = null;
            return;
        }

        if (! empty($this->form['ftp_account_id'])) {
            $this->testFtpConnection();
        }
    }

    public function updatedFormSshEnabled(bool $value): void
    {
        if (! $value) {
            return;
        }

        if (empty($this->form['ftp_account_id'] ?? null)) {
            $this->dispatch('notify', message: 'Select an FTP/SSH access record to use for SSH deployments.');
        }
    }

    public function nextStep(): void
    {
        $this->validate($this->stepRules(1));
        $this->step = 2;
    }

    public function previousStep(): void
    {
        $this->step = 1;
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());
        $project = Auth::user()->projects()->create($validated['form']);
        $message = 'Project created.';
        $bootstrapOk = true;

        if (! empty($project->repo_url)) {
            try {
                $result = app(RepositoryBootstrapper::class)->bootstrap($project);
                if ($result['status'] === 'bootstrapped') {
                    $message = ! empty($result['dirty'])
                        ? 'Project created. Repository linked, but existing files differ from origin; use force deploy to overwrite.'
                        : 'Project created. Repository linked and ready to deploy.';
                }
            } catch (\Throwable $exception) {
                $message = 'Project created, but repository setup failed: '.$exception->getMessage();
                $bootstrapOk = false;
            }
        }

        $envWarning = $this->envSetupWarning($project);
        if ($envWarning) {
            $message .= ' '.$envWarning;
        }

        $repoReady = $bootstrapOk && $this->projectRepoReady($project);
        $envReady = $envWarning === null;
        if ($project->auto_deploy && $repoReady && $envReady) {
            if ($this->queueEnabled()) {
                app(\App\Services\DeploymentQueueService::class)->enqueue($project, 'deploy', ['reason' => 'project_created'], Auth::user());
                $message .= ' Initial deployment queued.';
                app()->terminating(function () {
                    app(\App\Services\DeploymentQueueService::class)->processNext(1);
                });
            } else {
                $deployment = app(\App\Services\DeploymentService::class)->deploy($project, Auth::user());
                $message .= $deployment->status === 'success'
                    ? ' Initial deployment completed.'
                    : ' Initial deployment failed. Check logs for details.';
            }
        }

        $this->dispatch('notify', message: $message);
        $this->redirectRoute('projects.index', navigate: true);
    }

    public function checkPathPermissions(): void
    {
        $path = (string) ($this->form['local_path'] ?? '');
        $result = app(\App\Services\DeploymentService::class)->checkPathPermissions($path);

        $this->permissionStatus = $result['status'] ?? null;
        $this->permissionMessage = $result['message'] ?? null;
        $this->permissionParent = $result['parent'] ?? null;
    }

    public function testFtpConnection(): void
    {
        $accountId = $this->form['ftp_account_id'] ?? null;
        if (! $accountId) {
            $this->ftpTestStatus = 'error';
            $this->ftpTestMessage = 'Select an FTP/SSH access record to test.';
            return;
        }

        $account = \App\Models\FtpAccount::query()->find($accountId);
        if (! $account) {
            $this->ftpTestStatus = 'error';
            $this->ftpTestMessage = 'FTP/SSH access record not found.';
            return;
        }

        $rootPath = trim((string) ($this->form['ftp_root_path'] ?? ''));
        $result = app(\App\Services\FtpService::class)->testAccount($account, $rootPath !== '' ? $rootPath : null);

        $this->ftpTestStatus = $result['status'] ?? 'error';
        $this->ftpTestMessage = $result['message'] ?? 'Unable to test FTP connection.';
    }

    private function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:255'],
            'form.project_type' => ['required', 'string', Rule::in(['laravel', 'node', 'static', 'custom'])],
            'form.repo_url' => ['nullable', 'string', 'max:255'],
            'form.site_url' => ['nullable', 'url', 'max:255'],
            'form.local_path' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'local_path'),
            ],
            'form.default_branch' => ['required', 'string', 'max:255'],
            'form.auto_deploy' => ['boolean'],
            'form.health_url' => ['nullable', 'string', 'max:255'],
            'form.run_composer_install' => ['boolean'],
            'form.run_npm_install' => ['boolean'],
            'form.run_build_command' => ['boolean'],
            'form.build_command' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn () => (bool) ($this->form['run_build_command'] ?? false)),
            ],
            'form.run_test_command' => ['boolean'],
            'form.test_command' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn () => (bool) ($this->form['run_test_command'] ?? false)),
            ],
            'form.allow_dependency_updates' => ['boolean'],
            'form.exclude_paths' => ['nullable', 'string'],
            'form.ftp_enabled' => ['boolean'],
            'form.ftp_account_id' => [
                'nullable',
                Rule::requiredIf(fn () => (bool) ($this->form['ftp_enabled'] ?? false) || (bool) ($this->form['ssh_enabled'] ?? false)),
                'exists:ftp_accounts,id',
            ],
            'form.ftp_root_path' => ['nullable', 'string', 'max:255'],
            'form.ssh_enabled' => ['boolean'],
            'form.ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'form.ssh_root_path' => ['nullable', 'string', 'max:255'],
            'form.ssh_commands' => ['nullable', 'string'],
        ];
    }

    private function stepRules(int $step): array
    {
        if ($step === 1) {
            return [
                'form.name' => ['required', 'string', 'max:255'],
                'form.project_type' => ['required', 'string', Rule::in(['laravel', 'node', 'static', 'custom'])],
                'form.repo_url' => ['nullable', 'string', 'max:255'],
                'form.site_url' => ['nullable', 'url', 'max:255'],
                'form.local_path' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('projects', 'local_path'),
                ],
                'form.default_branch' => ['required', 'string', 'max:255'],
                'form.health_url' => ['nullable', 'string', 'max:255'],
                'form.exclude_paths' => ['nullable', 'string'],
                'form.ftp_enabled' => ['boolean'],
                'form.ftp_account_id' => [
                    'nullable',
                    Rule::requiredIf(fn () => (bool) ($this->form['ftp_enabled'] ?? false) || (bool) ($this->form['ssh_enabled'] ?? false)),
                    'exists:ftp_accounts,id',
                ],
                'form.ftp_root_path' => ['nullable', 'string', 'max:255'],
                'form.ssh_enabled' => ['boolean'],
                'form.ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'form.ssh_root_path' => ['nullable', 'string', 'max:255'],
                'form.ssh_commands' => ['nullable', 'string'],
            ];
        }

        return $this->rules();
    }

    private function applyProjectTypeDefaults(string $type): void
    {
        $type = $type ?: 'custom';

        $defaults = match ($type) {
            'laravel' => [
                'health_url' => '/up',
                'run_composer_install' => true,
                'run_npm_install' => true,
                'run_build_command' => true,
                'build_command' => 'npm run build',
                'run_test_command' => true,
                'test_command' => 'php artisan test',
                'allow_dependency_updates' => true,
            ],
            'node' => [
                'health_url' => '',
                'run_composer_install' => false,
                'run_npm_install' => true,
                'run_build_command' => true,
                'build_command' => 'npm run build',
                'run_test_command' => false,
                'test_command' => 'npm test',
                'allow_dependency_updates' => true,
            ],
            'static' => [
                'health_url' => '',
                'run_composer_install' => false,
                'run_npm_install' => true,
                'run_build_command' => true,
                'build_command' => 'npm run build',
                'run_test_command' => false,
                'test_command' => '',
                'allow_dependency_updates' => false,
            ],
            default => [
                'health_url' => '',
                'run_composer_install' => false,
                'run_npm_install' => false,
                'run_build_command' => false,
                'build_command' => '',
                'run_test_command' => false,
                'test_command' => '',
                'allow_dependency_updates' => false,
            ],
        };

        foreach ($defaults as $key => $value) {
            $this->form[$key] = $value;
        }
    }

    private function clearPermissionCheck(): void
    {
        $this->permissionStatus = null;
        $this->permissionMessage = null;
        $this->permissionParent = null;
    }

    private function envSetupWarning(\App\Models\Project $project): ?string
    {
        if (($project->project_type ?? '') !== 'laravel') {
            return null;
        }

        $path = trim((string) $project->local_path);
        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        $laravelRoot = $this->findLaravelRoot($path) ?? $path;
        $envPath = $laravelRoot.DIRECTORY_SEPARATOR.'.env';
        if (is_file($envPath)) {
            return null;
        }

        return 'Missing .env file. Open the Environment tab to create it before deploying.';
    }

    private function projectRepoReady(\App\Models\Project $project): bool
    {
        $path = trim((string) ($project->local_path ?? ''));
        if ($path !== '' && is_dir($path.DIRECTORY_SEPARATOR.'.git')) {
            return true;
        }

        return ! empty($project->repo_url);
    }

    private function queueEnabled(): bool
    {
        return (bool) config('gitmanager.deploy_queue.enabled', true);
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
