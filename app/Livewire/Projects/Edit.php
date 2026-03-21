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
        ];
    }

    public function render()
    {
        return view('livewire.projects.edit')
            ->layout('layouts.app', [
                'header' => view('livewire.projects.partials.edit-header', [
                    'project' => $this->project,
                ]),
            ]);
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());
        $this->project->update($validated['form']);

        $this->dispatch('notify', message: 'Project updated.');
        $this->redirectRoute('projects.index', navigate: true);
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
        ];
    }
}
