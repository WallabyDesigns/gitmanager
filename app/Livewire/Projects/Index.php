<?php

namespace App\Livewire\Projects;

use App\Models\Deployment;
use App\Services\DeploymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public function refreshHealth(DeploymentService $service): void
    {
        $projects = Auth::user()
            ->projects()
            ->get();

        foreach ($projects as $project) {
            if (! $project->health_checked_at || $project->health_checked_at->lt(now()->subMinute())) {
                $service->checkHealth($project);
            }
        }
    }

    public function render()
    {
        return view('livewire.projects.index', [
            'projects' => Auth::user()
                ->projects()
                ->addSelect([
                    'last_successful_deploy_at' => Deployment::query()
                        ->select('started_at')
                        ->whereColumn('project_id', 'projects.id')
                        ->where('action', 'deploy')
                        ->where('status', 'success')
                        ->latest('started_at')
                        ->limit(1),
                ])
                ->latest()
                ->get(),
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.index-header'),
        ]);
    }

}
