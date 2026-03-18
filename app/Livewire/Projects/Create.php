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
        ];

        $this->applyProjectTypeDefaults('laravel');
    }

    public function render()
    {
        return view('livewire.projects.create')
            ->layout('layouts.app', [
                'header' => view('livewire.projects.partials.create-header'),
            ]);
    }

    public function updatedFormProjectType(string $value): void
    {
        $this->applyProjectTypeDefaults($value);
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
            'form.project_type' => ['required', 'string', Rule::in(['laravel', 'node', 'static', 'custom'])],
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

    private function stepRules(int $step): array
    {
        if ($step === 1) {
            return [
                'form.name' => ['required', 'string', 'max:255'],
                'form.project_type' => ['required', 'string', Rule::in(['laravel', 'node', 'static', 'custom'])],
                'form.repo_url' => ['nullable', 'string', 'max:255'],
                'form.local_path' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('projects', 'local_path'),
                ],
                'form.default_branch' => ['required', 'string', 'max:255'],
                'form.health_url' => ['nullable', 'string', 'max:255'],
                'form.exclude_paths' => ['nullable', 'string'],
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
}
