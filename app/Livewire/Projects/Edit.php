<?php

namespace App\Livewire\Projects;

use App\Models\Project;
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
    public ?string $ftpTestStatus = null;
    public ?string $ftpTestMessage = null;

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);
        $this->project = $project;
        $this->form = [
            'name' => $project->name,
            'project_type' => $project->project_type ?? 'custom',
            'repo_url' => $project->repo_url,
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
            'ftp_enabled' => $project->ftp_enabled,
            'ftp_account_id' => $project->ftp_account_id,
            'ftp_root_path' => $project->ftp_root_path,
            'ssh_enabled' => $project->ssh_enabled,
            'ssh_port' => $project->ssh_port ?? 22,
            'ssh_root_path' => $project->ssh_root_path,
            'ssh_commands' => $project->ssh_commands,
        ];
    }

    public function render()
    {
        return view('livewire.projects.edit', [
            'ftpAccounts' => \App\Models\FtpAccount::query()->orderBy('name')->get(),
        ])
            ->layout('layouts.app', [
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

    public function save(): void
    {
        $validated = $this->validate($this->rules());
        $this->project->update($validated['form']);

        $this->dispatch('notify', message: 'Project updated.');
        $this->redirectRoute('projects.index', navigate: true);
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
}
