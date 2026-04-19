<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\EditionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Edit extends Component
{
    use AuthorizesRequests;

    public string $projectsTab = 'list';

    public Project $project;
    public array $form = [];
    public array $projectTypes = [];
    public bool $isEnterprise = false;
    public string $lastAllowedProjectType = 'custom';
    public ?string $ftpTestStatus = null;
    public ?string $ftpTestMessage = null;
    public int $containerProjectLimit = 3;
    public int $containerProjectCount = 0;

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);
        $this->project = $project;
        $edition = app(EditionService::class);
        $this->isEnterprise = $edition->current() === EditionService::ENTERPRISE;
        $this->containerProjectLimit = $this->resolveContainerProjectLimit();
        $this->refreshContainerProjectStats();
        $this->projectTypes = $this->projectTypeOptions($this->isEnterprise);
        $this->form = [
            'name' => $project->name,
            'project_type' => $project->project_type ?? 'custom',
            'repo_url' => $project->repo_url,
            'site_url' => $project->site_url,
            'local_path' => $project->local_path,
            'default_branch' => $project->default_branch,
            'auto_deploy' => $project->auto_deploy,
            'health_url' => $project->health_url,
            'run_composer_install' => $project->run_composer_install,
            'run_npm_install' => $project->run_npm_install,
            'run_build_command' => $project->run_build_command,
            'build_command' => $project->build_command,
            'run_test_command' => $project->run_test_command,
            'test_command' => $project->test_command,
            'allow_dependency_updates' => $project->allow_dependency_updates,
            'exclude_paths' => $project->exclude_paths,
            'whitelist_paths' => $project->whitelist_paths,
            'env_content' => $this->seedContent($project, '.env'),
            'htaccess_content' => $this->seedContent($project, '.htaccess'),
            'ftp_enabled' => $project->ftp_enabled,
            'ftp_account_id' => $project->ftp_account_id,
            'ftp_root_path' => $project->ftp_root_path,
            'ssh_enabled' => $project->ssh_enabled,
            'ssh_port' => $project->ssh_port ?? 22,
            'ssh_root_path' => $project->ssh_root_path,
            'ssh_commands' => $project->ssh_commands,
            'ignore_migration_table_exists' => $project->ignore_migration_table_exists ?? false,
        ];
        $this->lastAllowedProjectType = (string) ($this->form['project_type'] ?? 'custom');
    }

    public function render()
    {
        return view('livewire.projects.edit', [
            'ftpAccounts' => \App\Models\FtpAccount::query()->orderBy('name')->get(),
            'projectTypes' => $this->projectTypes,
        ])
            ->layout('layouts.app', [
                'title' => 'Edit ' . $this->project->name,
                'header' => view('livewire.projects.partials.edit-header', [
                    'project' => $this->project,
                ]),
            ]);
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

    public function updatedFormProjectType(string $value): void
    {
        if ($value === 'container' && ! $this->canUseContainerProjectType()) {
            $this->addError('form.project_type', $this->containerLimitMessage());
            $this->dispatch('gwm-open-enterprise-modal', feature: 'Unlimited Container Projects');
            $this->form['project_type'] = $this->lastAllowedProjectType !== ''
                ? $this->lastAllowedProjectType
                : 'custom';
            return;
        }

        if ($this->isPremiumProjectType($value) && ! $this->isEnterprise) {
            $this->dispatch('gwm-open-enterprise-modal', feature: $this->projectTypeLabel($value).' Project Type');
            $this->form['project_type'] = $this->lastAllowedProjectType !== ''
                ? $this->lastAllowedProjectType
                : 'custom';
            return;
        }

        $this->lastAllowedProjectType = $value;
    }

    public function save(): void
    {
        $projectType = (string) ($this->form['project_type'] ?? '');
        if ($projectType === 'container' && ! $this->canUseContainerProjectType()) {
            $this->addError('form.project_type', $this->containerLimitMessage());
            $this->dispatch('gwm-open-enterprise-modal', feature: 'Unlimited Container Projects');
            return;
        }

        if ($this->isPremiumProjectType($projectType) && ! $this->isEnterprise) {
            $this->addError('form.project_type', $this->projectTypeLabel($projectType).' is available in Enterprise Edition.');
            $this->dispatch('gwm-open-enterprise-modal', feature: $this->projectTypeLabel($projectType).' Project Type');
            return;
        }

        $validated = $this->validate($this->rules());
        $payload = $validated['form'];
        $payload['ftp_root_path'] = $this->normalizeOptionalPath($payload['ftp_root_path'] ?? null);
        $payload['ssh_root_path'] = $this->normalizeOptionalPath($payload['ssh_root_path'] ?? null);
        $this->project->update($payload);

        $envNotes = $this->applyEnvContent($this->project, (string) ($validated['form']['env_content'] ?? ''));
        $htaccessNotes = $this->applyHtaccessContent($this->project, (string) ($validated['form']['htaccess_content'] ?? ''));

        $notes = array_values(array_filter(array_merge($envNotes, $htaccessNotes)));
        $message = 'Project updated.';
        if (! empty($notes)) {
            $message .= ' '.implode(' ', $notes);
        }

        $this->dispatch('notify', message: $message);
        $this->redirectRoute('projects.index', navigate: false);
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
            'form.project_type' => ['required', 'string', Rule::in(['laravel', 'node', 'static', 'nextjs', 'react', 'python', 'container', 'custom'])],
            'form.repo_url' => ['nullable', 'string', 'max:255'],
            'form.site_url' => ['nullable', 'url', 'max:255'],
            'form.local_path' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'local_path')->ignore($this->project->id),
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
            'form.whitelist_paths' => ['nullable', 'string'],
            'form.env_content' => ['nullable', 'string'],
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

    private function normalizeOptionalPath($value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function seedContent(Project $project, string $filename): string
    {
        $path = app(\App\Services\ProjectSeedService::class)->seedPath($project, $filename);
        if (! is_file($path)) {
            return '';
        }

        $contents = @file_get_contents($path);
        return $contents === false ? '' : $contents;
    }

    /**
     * @return array<int, string>
     */
    private function applyEnvContent(Project $project, string $content): array
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
    private function applyHtaccessContent(Project $project, string $content): array
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

    /**
     * @return array<int, array{value: string, label: string, description: string, locked: bool, locked_message?: string}>
     */
    private function projectTypeOptions(bool $isEnterprise): array
    {
        $canUseContainerType = $this->canUseContainerProjectType();

        return [
            [
                'value' => 'laravel',
                'label' => 'Laravel',
                'description' => 'Composer, npm, Vite build, and artisan tests.',
                'locked' => false,
            ],
            [
                'value' => 'node',
                'label' => 'Node',
                'description' => 'npm install/build/test workflow.',
                'locked' => false,
            ],
            [
                'value' => 'static',
                'label' => 'Static',
                'description' => 'Static or frontend-only build output.',
                'locked' => false,
            ],
            [
                'value' => 'nextjs',
                'label' => 'Next.js',
                'description' => 'Dynamic React SSR/ISR pipeline (Enterprise).',
                'locked' => ! $isEnterprise,
                'locked_message' => 'Next.js projects are available in Enterprise Edition.',
            ],
            [
                'value' => 'react',
                'label' => 'React App',
                'description' => 'Dynamic React SPA pipeline (Enterprise).',
                'locked' => ! $isEnterprise,
                'locked_message' => 'React App projects are available in Enterprise Edition.',
            ],
            [
                'value' => 'python',
                'label' => 'Python',
                'description' => 'Python virtualenv + pip pipeline (Enterprise).',
                'locked' => ! $isEnterprise,
                'locked_message' => 'Python projects are available in Enterprise Edition.',
            ],
            [
                'value' => 'container',
                'label' => 'Container',
                'description' => $isEnterprise
                    ? 'Containerized workload pipeline.'
                    : 'Containerized workload pipeline (Community includes '.$this->containerProjectLimit.' project slots).',
                'locked' => ! $canUseContainerType,
                'locked_message' => $isEnterprise
                    ? 'Container projects are enabled.'
                    : $this->containerLimitMessage(),
            ],
            [
                'value' => 'custom',
                'label' => 'Custom',
                'description' => 'Manually configure build/deploy settings.',
                'locked' => false,
            ],
        ];
    }

    private function isPremiumProjectType(string $type): bool
    {
        return in_array($type, ['python', 'nextjs', 'react'], true);
    }

    private function projectTypeLabel(string $type): string
    {
        foreach ($this->projectTypes as $item) {
            if (($item['value'] ?? '') === $type) {
                return (string) ($item['label'] ?? $type);
            }
        }

        return ucfirst($type);
    }

    private function refreshContainerProjectStats(): void
    {
        $userId = Auth::id();
        if (! $userId) {
            $this->containerProjectCount = 0;
            return;
        }

        $this->containerProjectCount = Project::query()
            ->where('user_id', $userId)
            ->where('project_type', 'container')
            ->count();
    }

    private function canUseContainerProjectType(): bool
    {
        if ($this->isEnterprise) {
            return true;
        }

        $currentType = (string) ($this->project->project_type ?? '');
        if ($currentType === 'container') {
            return true;
        }

        return $this->containerProjectCount < $this->containerProjectLimit;
    }

    private function resolveContainerProjectLimit(): int
    {
        if (class_exists(\GitManagerEnterprise\Support\EnterpriseFeatureConfig::class)) {
            return max(1, \GitManagerEnterprise\Support\EnterpriseFeatureConfig::dockerFreeNodeLimit());
        }

        return max(1, (int) config('gitmanager.docker_free_node_limit', 3));
    }

    private function containerLimitMessage(): string
    {
        return 'You have reached the Community limit of '.$this->containerProjectLimit.' container projects. Upgrade to Enterprise for unlimited container projects.';
    }
}
