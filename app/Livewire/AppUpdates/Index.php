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
        return view('livewire.app-updates.index', [
            'latest' => AppUpdate::query()->orderByDesc('started_at')->first(),
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
}
