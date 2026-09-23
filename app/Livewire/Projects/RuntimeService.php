<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\RuntimeServiceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class RuntimeService extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public bool $showDetails = false;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    public function start(RuntimeServiceService $service): void
    {
        $this->control($service, 'start');
    }

    public function stop(RuntimeServiceService $service): void
    {
        $this->control($service, 'stop');
    }

    public function restart(RuntimeServiceService $service): void
    {
        $this->control($service, 'restart');
    }

    public function render(RuntimeServiceService $service): View
    {
        return view('livewire.projects.runtime-service', [
            'status' => $service->status($this->project),
            'isLarust' => $this->project->project_type === 'larust',
        ]);
    }

    private function control(RuntimeServiceService $service, string $action): void
    {
        $result = $service->control($this->project, $action);
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }
}
