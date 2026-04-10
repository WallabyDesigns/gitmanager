<?php

namespace App\Livewire\AppUpdates;

use App\Models\AppUpdate;
use App\Services\SelfUpdateService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public array $updateStatus = [];
    public bool $checkUpdatesEnabled = true;
    public bool $autoUpdateEnabled = true;
    public array $pendingChanges = [];

    public function mount(SelfUpdateService $service, SettingsService $settings): void
    {
        $this->checkUpdatesEnabled = (bool) $settings->get('system.check_updates', true);
        $this->autoUpdateEnabled = (bool) $settings->get(
            'system.auto_update',
            (bool) config('gitmanager.self_update.enabled', true)
        );

        $this->updateStatus = $this->checkUpdatesEnabled
            ? $service->getUpdateStatus()
            : [
                'status' => 'disabled',
                'current' => $service->getCurrentVersionHash(),
            ];
        $this->loadPendingChanges($service);
    }

    public function render()
    {
        $updates = AppUpdate::query()->orderByDesc('started_at');

        return view('livewire.app-updates.index', [
            'latest' => $updates->first(),
            'recent' => (clone $updates)->take(10)->get(),
            'checkUpdatesEnabled' => $this->checkUpdatesEnabled,
            'autoUpdateEnabled' => $this->autoUpdateEnabled,
            'pendingChanges' => $this->pendingChanges,
        ])->layout('layouts.app', [
            'title' => 'System Updates',
            'header' => view('livewire.app-updates.partials.header'),
        ]);
    }

    public function runUpdate(SelfUpdateService $service): void
    {
        $update = $service->updateSmart(Auth::user());

        $message = match ($update->status) {
            'success' => 'Git Web Manager updated successfully.',
            'skipped' => 'Git Web Manager is already up to date.',
            'warning' => 'Update applied with warnings. Review the logs for details.',
            default => 'Update failed. Review the logs for details.',
        };

        $this->updateStatus = $service->getUpdateStatus(true);
        $this->loadPendingChanges($service);
        $this->dispatch('notify', message: $message);
        $this->redirectRoute('system.updates', navigate: false);
    }

    public function runForceUpdate(SelfUpdateService $service): void
    {
        $update = $service->forceUpdate(Auth::user());

        $message = match ($update->status) {
            'success' => 'Git Web Manager force-updated successfully.',
            'skipped' => 'Git Web Manager is already up to date.',
            'warning' => 'Force update applied with warnings. Review the logs for details.',
            default => 'Force update failed. Review the logs for details.',
        };

        $this->updateStatus = $service->getUpdateStatus(true);
        $this->loadPendingChanges($service);
        $this->dispatch('notify', message: $message);
        $this->redirectRoute('system.updates', navigate: false);
    }

    public function refreshUpdateStatus(SelfUpdateService $service): void
    {
        if (! $this->checkUpdatesEnabled) {
            $this->dispatch('notify', message: 'Update checks are disabled in System Settings.');
            return;
        }

        $this->updateStatus = $service->getUpdateStatus(true);
        $this->loadPendingChanges($service);

        $message = match ($this->updateStatus['status'] ?? 'unknown') {
            'up-to-date' => 'Git Web Manager is up to date.',
            'update-available' => 'A newer version is available.',
            default => 'Unable to determine update status.',
        };

        $this->dispatch('notify', message: $message);
    }

    private function loadPendingChanges(SelfUpdateService $service): void
    {
        $this->pendingChanges = $this->checkUpdatesEnabled
            ? $service->getPendingChanges($this->updateStatus['current'] ?? null, $this->updateStatus['latest'] ?? null)
            : $service->getPendingChangesPreview();
    }
}
