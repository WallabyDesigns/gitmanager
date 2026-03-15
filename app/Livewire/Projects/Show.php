<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\DeploymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Show extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        abort_unless($project->user_id === Auth::id(), 403);
        $this->project = $project;
    }

    public function render()
    {
        return view('livewire.projects.show', [
            'deployments' => $this->project->deployments()->take(10)->get(),
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.show-header', [
                'project' => $this->project,
            ]),
        ]);
    }

    public function deploy(DeploymentService $service): void
    {
        $deployment = $service->deploy($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Deployment completed.'
            : 'Deployment failed. Check logs below.');
    }

    public function forceDeploy(DeploymentService $service): void
    {
        $deployment = $service->deploy($this->project, Auth::user(), true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Force deployment completed.'
            : 'Force deployment failed. Check logs below.');
    }

    public function rollback(DeploymentService $service): void
    {
        $deployment = $service->rollback($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Rollback completed.'
            : 'Rollback failed. Check logs below.');
    }

    public function checkUpdates(DeploymentService $service): void
    {
        $hasUpdates = $service->checkForUpdates($this->project);
        $this->project->refresh();
        $this->dispatch('notify', message: $hasUpdates
            ? 'Updates available for this project.'
            : 'No updates detected.');
    }

    public function checkHealth(DeploymentService $service): void
    {
        $status = $service->checkHealth($this->project);
        $this->project->refresh();
        $this->dispatch('notify', message: match ($status) {
            'ok' => 'Health check passed.',
            'fail' => 'Health check failed.',
            default => 'Health check not configured.',
        });
    }

    public function updateDependencies(DeploymentService $service): void
    {
        $deployment = $service->updateDependencies($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Dependency update completed.'
            : 'Dependency update failed. Check logs below.');
    }

    public function deleteProject(): void
    {
        $project = $this->project;
        abort_unless($project->user_id === Auth::id(), 403);

        $project->delete();

        $this->dispatch('notify', message: 'Project deleted.');
        $this->redirectRoute('projects.index', navigate: true);
    }
}
