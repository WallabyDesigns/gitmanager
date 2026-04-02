<?php

namespace App\Livewire\Security;

use App\Models\AppUpdate;
use App\Models\SecurityAlert;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url]
    public string $tab = 'current';
    public function render()
    {
        $tab = in_array($this->tab, ['current', 'resolved'], true) ? $this->tab : 'current';

        $query = SecurityAlert::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project');

        $alerts = $tab === 'resolved'
            ? $query->where('state', '!=', 'open')
            : $query->where('state', 'open');

        $latestUpdate = AppUpdate::query()->orderByDesc('started_at')->first();

        return view('livewire.security.index', [
            'alerts' => $alerts->orderByDesc('alert_created_at')->get(),
            'openCount' => SecurityAlert::query()
                ->where('state', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                ->count(),
            'resolvedCount' => SecurityAlert::query()
                ->where('state', '!=', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                ->count(),
            'tab' => $tab,
            'appUpdateFailed' => $latestUpdate && $latestUpdate->status === 'failed',
            'latestUpdate' => $latestUpdate,
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
