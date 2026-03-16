<?php

namespace App\Livewire\AppUpdates;

use App\Models\AppUpdate;
use App\Services\SelfUpdateService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
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
        $update = $service->update(Auth::user());

        $message = match ($update->status) {
            'success' => 'Git Project Manager updated successfully.',
            'skipped' => 'Git Project Manager is already up to date.',
            default => 'Update failed. Review the logs for details.',
        };

        $this->dispatch('notify', message: $message);
        $this->redirectRoute('app-updates.index', navigate: true);
    }

    public function runUpdatePreserve(SelfUpdateService $service): void
    {
        $update = $service->update(Auth::user(), true);

        $message = match ($update->status) {
            'success' => 'Update completed. Local changes were preserved.',
            'skipped' => 'Git Project Manager is already up to date.',
            default => 'Update failed. Review the logs for details.',
        };

        $this->dispatch('notify', message: $message);
        $this->redirectRoute('app-updates.index', navigate: true);
    }
}
