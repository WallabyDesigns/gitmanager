<?php

namespace App\Livewire\Projects;

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
        Auth::user()->projects()->create($validated['form']);

        $this->dispatch('notify', message: 'Project created.');
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
