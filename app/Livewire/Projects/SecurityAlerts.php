<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\SecurityAlert;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

class SecurityAlerts extends Component
{
    public Project $project;
    public string $tab = 'current';
    public bool $sslVerifyEnabled = true;

    public function mount(Project $project, SettingsService $settings): void
    {
        $this->project = $project;
        $this->sslVerifyEnabled = (bool) ($settings->get(
            'system.github_ssl_verify',
            (bool) config('services.github.verify_ssl', true)
        ));
    }

    public function render()
    {
        $tab = in_array($this->tab, ['current', 'resolved'], true) ? $this->tab : 'current';

        $query = SecurityAlert::query()
            ->where('project_id', $this->project->id);

        $alerts = $tab === 'resolved'
            ? $query->where('state', '!=', 'open')
            : $query->where('state', 'open');

        return view('livewire.projects.security-alerts', [
            'alerts' => $alerts->orderByDesc('alert_created_at')->get(),
            'openCount' => SecurityAlert::query()
                ->where('project_id', $this->project->id)
                ->where('state', 'open')
                ->count(),
            'resolvedCount' => SecurityAlert::query()
                ->where('project_id', $this->project->id)
                ->where('state', '!=', 'open')
                ->count(),
            'tab' => $tab,
            'sslVerifyEnabled' => $this->sslVerifyEnabled,
        ]);
    }

    public function sync(): void
    {
        try {
            Artisan::call('security:sync');
            $this->dispatch('notify', message: 'Security alerts synced.');
        } catch (\Throwable $exception) {
            $this->dispatch('notify', message: 'Security sync failed: '.$exception->getMessage());
        }
    }
}
