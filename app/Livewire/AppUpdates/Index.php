<?php

namespace App\Livewire\AppUpdates;

use App\Models\AppUpdate;
use App\Services\SelfUpdateService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public array $updateStatus = [];

    public function mount(SelfUpdateService $service): void
    {
        $this->updateStatus = $service->getUpdateStatus();
    }

    public function render()
    {
        $updates = AppUpdate::query()->orderByDesc('started_at');

        return view('livewire.app-updates.index', [
            'latest' => $updates->first(),
            'recent' => (clone $updates)->take(10)->get(),
            'selfUpdateEnabled' => (bool) config('gitmanager.self_update.enabled', true),
        ])->layout('layouts.app', [
            'header' => view('livewire.app-updates.partials.header'),
        ]);
    }

    public function runUpdate(SelfUpdateService $service): void
    {
        $update = $service->updateSmart(Auth::user());

        $message = match ($update->status) {
            'success' => 'Git Web Manager updated successfully.',
            'skipped' => 'Git Web Manager is already up to date.',
            default => 'Update failed. Review the logs for details.',
        };

        $this->dispatch('notify', message: $message);
        $this->redirectRoute('app-updates.index', navigate: true);
    }

    public function refreshUpdateStatus(SelfUpdateService $service): void
    {
        $this->updateStatus = $service->getUpdateStatus(true);

        $message = match ($this->updateStatus['status'] ?? 'unknown') {
            'up-to-date' => 'Git Web Manager is up to date.',
            'update-available' => 'A newer version is available.',
            default => 'Unable to determine update status.',
        };

        $this->dispatch('notify', message: $message);
    }
}
