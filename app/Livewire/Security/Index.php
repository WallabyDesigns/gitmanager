<?php

namespace App\Livewire\Security;

use App\Models\AppUpdate;
use App\Models\AuditIssue;
use App\Models\Deployment;
use App\Models\Project;
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
        $projectShell = request()->routeIs('projects.action-center');
        $canSyncAlerts = Auth::user()?->isAdmin() ?? false;

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

        $dependencyProjects = $tab === 'current'
            ? $this->dependencyIssueProjects()
            : collect();
        $dependencyIssueCount = $dependencyProjects->count();

        $latestUpdate = $canSyncAlerts
            ? AppUpdate::query()->orderByDesc('started_at')->first()
            : null;
        $updateIssueCount = $latestUpdate && $latestUpdate->status === 'failed' ? 1 : 0;
        $openCount = SecurityAlert::query()
            ->where('state', 'open')
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->count()
            + AuditIssue::query()
                ->where('status', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                ->count()
            + $dependencyIssueCount;

        return view('livewire.security.index', [
            'alerts' => $alerts->orderByDesc('alert_created_at')->get(),
            'openCount' => $openCount,
            'resolvedCount' => SecurityAlert::query()
                ->where('state', '!=', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                ->count()
                + AuditIssue::query()
                    ->where('status', 'resolved')
                    ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                    ->count(),
            'actionableCount' => $openCount + $updateIssueCount,
            'tab' => $tab,
            'appUpdateFailed' => $latestUpdate && $latestUpdate->status === 'failed',
            'latestUpdate' => $latestUpdate,
            'sslVerifyEnabled' => $this->sslVerifyEnabled,
            'auditIssues' => $auditIssues->orderByDesc('detected_at')->get(),
            'dependencyProjects' => $dependencyProjects,
            'dependencyIssueCount' => $dependencyIssueCount,
            'projectShell' => $projectShell,
            'canSyncAlerts' => $canSyncAlerts,
        ])->layout('layouts.app', [
            'title' => $projectShell ? 'Action Center' : 'Security',
            'header' => $projectShell
                ? view('livewire.projects.partials.action-center-header')
                : view('livewire.security.partials.header'),
        ]);
    }

    public function sync(): void
    {
        if (! (Auth::user()?->isAdmin() ?? false)) {
            $this->dispatch('notify', message: 'Only administrators can sync security alerts.');

            return;
        }

        Artisan::call('security:sync');
        $this->dispatch('notify', message: 'Security alerts synced.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Project>
     */
    private function dependencyIssueProjects()
    {
        return Project::query()
            ->where('user_id', Auth::id())
            ->addSelect([
                'last_composer_status' => Deployment::query()
                    ->select('status')
                    ->whereColumn('project_id', 'projects.id')
                    ->whereIn('action', ['composer_install', 'composer_update', 'composer_audit'])
                    ->latest('started_at')
                    ->limit(1),
                'last_npm_status' => Deployment::query()
                    ->select('status')
                    ->whereColumn('project_id', 'projects.id')
                    ->whereIn('action', ['npm_install', 'npm_update', 'npm_audit_fix', 'npm_audit_fix_force'])
                    ->latest('started_at')
                    ->limit(1),
            ])
            ->get()
            ->filter(function (Project $project): bool {
                return in_array($project->getAttribute('last_composer_status'), ['failed', 'warning'], true)
                    || in_array($project->getAttribute('last_npm_status'), ['failed', 'warning'], true);
            })
            ->values();
    }
}
