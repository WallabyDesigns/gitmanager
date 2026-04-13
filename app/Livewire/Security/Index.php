<?php

namespace App\Livewire\Security;

use App\Models\AppUpdate;
use App\Models\AuditIssue;
use App\Models\SecurityAlert;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url]
    public string $tab = 'current';
    public bool $sslVerifyEnabled = true;

    public function mount(SettingsService $settings): void
    {
        $this->sslVerifyEnabled = (bool) ($settings->get(
            'system.github_ssl_verify',
            (bool) config('services.github.verify_ssl', true)
        ));
    }

    public function render()
    {
        $tab = in_array($this->tab, ['current', 'resolved'], true) ? $this->tab : 'current';

        $query = SecurityAlert::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project');

        $alerts = $tab === 'resolved'
            ? $query->where('state', '!=', 'open')
            : $query->where('state', 'open');

        $auditQuery = AuditIssue::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project');

        $auditIssues = $tab === 'resolved'
            ? $auditQuery->where('status', 'resolved')
            : $auditQuery->where('status', 'open');

        $latestUpdate = AppUpdate::query()->orderByDesc('started_at')->first();

        return view('livewire.security.index', [
            'alerts' => $alerts->orderByDesc('alert_created_at')->get(),
            'openCount' => SecurityAlert::query()
                ->where('state', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                ->count()
                + AuditIssue::query()
                    ->where('status', 'open')
                    ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                    ->count(),
            'resolvedCount' => SecurityAlert::query()
                ->where('state', '!=', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                ->count()
                + AuditIssue::query()
                    ->where('status', 'resolved')
                    ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                    ->count(),
            'tab' => $tab,
            'appUpdateFailed' => $latestUpdate && $latestUpdate->status === 'failed',
            'latestUpdate' => $latestUpdate,
            'sslVerifyEnabled' => $this->sslVerifyEnabled,
            'auditIssues' => $auditIssues->orderByDesc('detected_at')->get(),
        ])->layout('layouts.app', [
            'title' => 'Security',
            'header' => view('livewire.security.partials.header'),
        ]);
    }

    public function sync(): void
    {
        Artisan::call('security:sync');
        $this->dispatch('notify', message: 'Security alerts synced.');
    }
}
