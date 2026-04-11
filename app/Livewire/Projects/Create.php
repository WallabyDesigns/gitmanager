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
    public bool $envTouched = false;
    public bool $htaccessTouched = false;
    public bool $envUseExampleTouched = false;
    public bool $envExampleAvailable = false;
    public bool $envExampleFetchFailed = false;
    public ?string $envExampleFetchKey = null;
    public ?string $envExampleSource = null;
    public ?string $envExampleMessage = null;
    public ?string $envExampleFilename = null;
    private bool $settingDefaults = false;

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
            'env_content' => '',
            'env_use_example' => false,
            'htaccess_content' => '',
            'ftp_enabled' => false,
            'ftp_account_id' => null,
            'ftp_root_path' => '',
            'ssh_enabled' => false,
            'ssh_port' => 22,
            'ssh_root_path' => '',
            'ssh_commands' => '',
            'ignore_migration_table_exists' => false,
        ];

        $this->applyProjectTypeDefaults('laravel');
        $this->prefillConfigurationDefaults(true);
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
        $this->prefillConfigurationDefaults();
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
            $this->prefillConfigurationDefaults();
            return;
        }

        $this->checkPathPermissions();
        $this->prefillConfigurationDefaults();
    }

    public function updatedFormRepoUrl(): void
    {
        $this->resetEnvExampleCache();
        if ($this->step >= 2) {
            $this->prefillConfigurationDefaults();
        }
    }

    public function updatedFormDefaultBranch(): void
    {
        $this->resetEnvExampleCache();
        if ($this->step >= 2) {
            $this->prefillConfigurationDefaults();
        }
    }

    public function updatedFormEnvContent(): void
    {
        if (! $this->settingDefaults) {
            $this->envTouched = true;
        }
    }

    public function updatedFormEnvUseExample($value): void
    {
        if (! $this->settingDefaults) {
            $this->envUseExampleTouched = true;
        }

        if (! $value) {
            $example = $this->envExampleContent();
            if ($example !== null && trim((string) ($this->form['env_content'] ?? '')) === trim($example)) {
                $this->settingDefaults = true;
                $this->form['env_content'] = '';
                $this->settingDefaults = false;
                $this->envTouched = false;
            }
            return;
        }

        if ($this->envTouched) {
            return;
        }

        $example = $this->envExampleContent();
        if ($example !== null && trim((string) ($this->form['env_content'] ?? '')) === '') {
            $this->settingDefaults = true;
            $this->form['env_content'] = $example;
            $this->settingDefaults = false;
        }
    }

    public function updatedFormHtaccessContent(): void
    {
        if (! $this->settingDefaults) {
            $this->htaccessTouched = true;
        }
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
        $this->validate($this->stepRules($this->step));
        $this->step = min(3, $this->step + 1);
        if ($this->step === 2) {
            $this->prefillConfigurationDefaults();
        }
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());
        $payload = $validated['form'];
        $payload['ftp_root_path'] = $this->normalizeOptionalPath($payload['ftp_root_path'] ?? null);
        $payload['ssh_root_path'] = $this->normalizeOptionalPath($payload['ssh_root_path'] ?? null);
        unset($payload['env_use_example']);
        $project = Auth::user()->projects()->create($payload);
        $message = 'Project created.';
        $bootstrapOk = true;
        $envNotes = [];
        $htaccessNotes = [];

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

        $envNotes = $this->applyEnvContent($project, (string) ($validated['form']['env_content'] ?? ''));
        $htaccessNotes = $this->applyHtaccessContent($project, (string) ($validated['form']['htaccess_content'] ?? ''));

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

        $notes = array_values(array_filter(array_merge($envNotes, $htaccessNotes)));
        if (! empty($notes)) {
            $message .= ' '.implode(' ', $notes);
        }

        $this->dispatch('notify', message: $message);
        $this->redirectRoute('projects.index', navigate: false);
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

        $rootPath = trim((string) ($this->form['local_path'] ?? ''));
        if ($rootPath === '') {
            $this->ftpTestStatus = 'error';
            $this->ftpTestMessage = 'Project Local Path is required for FTP sync.';
            return;
        }
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
            'form.ignore_migration_table_exists' => ['boolean'],
            'form.exclude_paths' => ['nullable', 'string'],
            'form.env_content' => ['nullable', 'string'],
            'form.env_use_example' => ['boolean'],
            'form.htaccess_content' => ['nullable', 'string'],
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
            'form.ignore_migration_table_exists' => ['boolean'],
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

        if ($step === 2) {
            return [
                'form.env_content' => ['nullable', 'string'],
                'form.env_use_example' => ['boolean'],
                'form.htaccess_content' => ['nullable', 'string'],
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

    private function resetEnvExampleCache(): void
    {
        $this->envExampleFetchKey = null;
        $this->envExampleFetchFailed = false;
        $this->envExampleSource = null;
        $this->envExampleMessage = null;
        $this->envExampleFilename = null;
        $this->envExampleAvailable = false;
        $this->envUseExampleTouched = false;
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

    private function prefillConfigurationDefaults(bool $force = false): void
    {
        $this->settingDefaults = true;

        $example = $this->envExampleContent();
        $this->envExampleAvailable = $example !== null;

        if ($force || ! $this->envUseExampleTouched) {
            $this->form['env_use_example'] = $this->envExampleAvailable;
        }

        $envContent = trim((string) ($this->form['env_content'] ?? ''));
        if (($force || ! $this->envTouched) && $envContent === '' && (bool) ($this->form['env_use_example'] ?? false)) {
            if ($example !== null) {
                $this->form['env_content'] = $example;
            }
        }

        $htaccessContent = trim((string) ($this->form['htaccess_content'] ?? ''));
        if (($force || ! $this->htaccessTouched) && $htaccessContent === '') {
            $this->form['htaccess_content'] = app(\App\Services\HtaccessTemplateService::class)
                ->forProjectType((string) ($this->form['project_type'] ?? 'custom'));
        }

        $this->settingDefaults = false;
    }

    private function envExampleContent(): ?string
    {
        $this->envExampleSource = null;
        $this->envExampleMessage = null;
        $this->envExampleFilename = null;

        $path = trim((string) ($this->form['local_path'] ?? ''));
        if ($path === '' || ! is_dir($path)) {
            return $this->envExampleContentFromRepo();
        }

        $root = $this->findLaravelRoot($path) ?? $path;
        $examplePath = $this->findEnvExamplePath($root);
        if (! $examplePath) {
            return $this->envExampleContentFromRepo();
        }

        $contents = @file_get_contents($examplePath);
        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $this->envExampleSource = 'local';
        $this->envExampleMessage = 'Loaded from local '.basename($examplePath).'.';
        $this->envExampleFilename = basename($examplePath);
        $this->envExampleFetchFailed = false;

        return $contents;
    }

    private function envExampleContentFromRepo(): ?string
    {
        $repoUrl = trim((string) ($this->form['repo_url'] ?? ''));
        if ($repoUrl === '') {
            return null;
        }

        $branch = trim((string) ($this->form['default_branch'] ?? 'main'));
        $fetchKey = sha1($repoUrl.'|'.$branch);
        if ($this->envExampleFetchKey === $fetchKey && $this->envExampleFetchFailed) {
            $this->envExampleMessage = 'Unable to fetch environment example from the repository yet.';
            return null;
        }

        $this->envExampleFetchKey = $fetchKey;
        $service = app(\App\Services\RepositoryFileService::class);
        $candidates = ['.env.example', '.env.sample'];

        foreach ($candidates as $candidate) {
            $contents = $service->readFile($repoUrl, $branch, $candidate);
            if ($contents !== null && trim($contents) !== '') {
                $this->envExampleSource = 'repo';
                $this->envExampleMessage = 'Loaded from repo '.$candidate.'.';
                $this->envExampleFilename = $candidate;
                $this->envExampleFetchFailed = false;

                return $contents;
            }
        }

        $this->envExampleFetchFailed = true;
        $this->envExampleMessage = 'Unable to fetch .env.example or .env.sample from the repository.';
        $this->envExampleSource = null;
        $this->envExampleFilename = null;

        return null;
    }

    private function findEnvExamplePath(string $root): ?string
    {
        $candidates = [
            $root.DIRECTORY_SEPARATOR.'.env.example',
            $root.DIRECTORY_SEPARATOR.'.env.sample',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function applyEnvContent(\App\Models\Project $project, string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $seeded = app(\App\Services\ProjectSeedService::class)->store($project, '.env', rtrim($content)."\n");

        $path = trim((string) $project->local_path);
        if ($path === '' || ! is_dir($path)) {
            return [$seeded
                ? '.env saved for the next deployment (project path not ready yet).'
                : '.env content was provided but the project path is not available yet.'];
        }

        $root = $this->findLaravelRoot($path) ?? $path;
        $envPath = $root.DIRECTORY_SEPARATOR.'.env';

        if (is_file($envPath)) {
            return ['.env already exists, so pasted content was not applied.'];
        }

        if (! is_writable($root)) {
            @chmod($root, 0775);
        }

        if (! is_writable($root)) {
            $seeded = app(\App\Services\ProjectSeedService::class)->store($project, '.env', rtrim($content)."\n");
            return [$seeded
                ? '.env saved for the next deployment (project root not writable).'
                : '.env content provided, but the project root is not writable.'];
        }

        $payload = rtrim($content)."\n";
        if (@file_put_contents($envPath, $payload) === false) {
            return ['Failed to write .env from the pasted content.'];
        }

        @chmod($envPath, 0664);

        return ['.env created from pasted content.'];
    }

    /**
     * @return array<int, string>
     */
    private function applyHtaccessContent(\App\Models\Project $project, string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $seeded = app(\App\Services\ProjectSeedService::class)->store($project, '.htaccess', rtrim($content)."\n");

        $path = trim((string) $project->local_path);
        if ($path === '' || ! is_dir($path)) {
            return [$seeded
                ? '.htaccess saved for the next deployment (project path not ready yet).'
                : '.htaccess content was provided but the project path is not available yet.'];
        }

        $targetRoot = $this->findLaravelRoot($path) ?? $path;
        $targetPath = $targetRoot.DIRECTORY_SEPARATOR.'.htaccess';

        if (is_file($targetPath)) {
            return ['.htaccess already exists, so pasted content was not applied.'];
        }

        if (! is_writable($targetRoot)) {
            @chmod($targetRoot, 0775);
        }

        if (! is_writable($targetRoot)) {
            $seeded = app(\App\Services\ProjectSeedService::class)->store($project, '.htaccess', rtrim($content)."\n");
            return [$seeded
                ? '.htaccess saved for the next deployment (project root not writable).'
                : '.htaccess content provided, but the project root is not writable.'];
        }

        $payload = rtrim($content)."\n";
        if (@file_put_contents($targetPath, $payload) === false) {
            return ['Failed to write .htaccess from the pasted content.'];
        }

        @chmod($targetPath, 0664);

        return ['.htaccess created from pasted content.'];
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

    private function normalizeOptionalPath($value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
