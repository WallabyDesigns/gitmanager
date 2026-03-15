<?php

namespace App\Livewire\Projects;

use App\Services\RepositoryBootstrapper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public array $form = [];

    public function mount(): void
    {
        $this->form = [
            'name' => '',
            'repo_url' => '',
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
        ];
    }

    public function render()
    {
        return view('livewire.projects.create')
            ->layout('layouts.app', [
                'header' => view('livewire.projects.partials.create-header'),
            ]);
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());
        $project = Auth::user()->projects()->create($validated['form']);
        $message = 'Project created.';

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
            }
        }

        $this->dispatch('notify', message: $message);
        $this->redirectRoute('projects.index', navigate: true);
    }

    private function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:255'],
            'form.repo_url' => ['nullable', 'string', 'max:255'],
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
            'form.build_command' => ['required', 'string', 'max:255'],
            'form.run_test_command' => ['boolean'],
            'form.test_command' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn () => (bool) ($this->form['run_test_command'] ?? false)),
            ],
            'form.allow_dependency_updates' => ['boolean'],
        ];
    }
}
