<?php

namespace App\Livewire\AppUpdates;

use App\Models\AppUpdate;
use App\Services\SelfUpdateService;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Index extends Component
{
    private const OUTPUT_LOG_TAIL_CHARS = 60000;

    public array $updateStatus = [];
    public bool $checkUpdatesEnabled = true;
    public bool $autoUpdateEnabled = true;
    public array $pendingChanges = [];
    public ?int $expandedUpdateId = null;
    public ?string $expandedUpdateLog = null;
    public bool $expandedUpdateLogTruncated = false;

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
                'current' => method_exists($service, 'getCurrentVersionHash') ? $service->getCurrentVersionHash() : null,
            ];
        $this->loadPendingChanges($service);
    }

    public function render()
    {
        $recent = $this->updateHistoryQuery()->take(10)->get();

        return view('livewire.app-updates.index', [
            'latest' => $this->latestUpdate(),
            'recent' => $recent,
            'checkUpdatesEnabled' => $this->checkUpdatesEnabled,
            'autoUpdateEnabled' => $this->autoUpdateEnabled,
            'pendingChanges' => $this->pendingChanges,
            'outputLogTailChars' => self::OUTPUT_LOG_TAIL_CHARS,
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
            'blocked' => 'Update is on hold because the latest GitHub deployment has not reported success.',
            'warning' => 'Update applied with warnings. Review the logs for details.',
            default => 'Update failed. Review the logs for details.',
        };

        $this->updateStatus = $service->getUpdateStatus(true);
        $this->loadPendingChanges($service);
        $this->resetExpandedUpdateLog();
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
        $this->resetExpandedUpdateLog();
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
        $this->resetExpandedUpdateLog();

        $message = match ($this->updateStatus['status'] ?? 'unknown') {
            'up-to-date' => 'Git Web Manager is up to date.',
            'update-available' => 'A newer version is available.',
            'blocked' => 'A newer version exists, but updates are on hold until GitHub reports a healthy deployment.',
            default => 'Unable to determine update status.',
        };

        $this->dispatch('notify', message: $message);
    }

    public function toggleUpdateLog(int $updateId): void
    {
        if ($this->expandedUpdateId === $updateId) {
            $this->resetExpandedUpdateLog();

            return;
        }

        $update = $this->updateHistoryQuery(includeOutputTail: true)
            ->whereKey($updateId)
            ->first();

        if (! $update || (int) ($update->output_log_length ?? 0) === 0) {
            $this->resetExpandedUpdateLog();

            return;
        }

        $this->expandedUpdateId = (int) $update->id;
        $this->expandedUpdateLog = $update->output_log_tail;
        $this->expandedUpdateLogTruncated = (int) ($update->output_log_length ?? 0) > self::OUTPUT_LOG_TAIL_CHARS;
    }

    private function loadPendingChanges(SelfUpdateService $service): void
    {
        if ($this->checkUpdatesEnabled) {
            $this->pendingChanges = method_exists($service, 'getPendingChanges')
                ? $service->getPendingChanges($this->updateStatus['current'] ?? null, $this->updateStatus['latest'] ?? null)
                : [];
            return;
        }

        $this->pendingChanges = method_exists($service, 'getPendingChangesPreview')
            ? $service->getPendingChangesPreview()
            : [];
    }

    private function latestUpdate(): ?AppUpdate
    {
        return $this->updateHistoryQuery(includeOutputTail: true)->first();
    }

    private function resetExpandedUpdateLog(): void
    {
        $this->expandedUpdateId = null;
        $this->expandedUpdateLog = null;
        $this->expandedUpdateLogTruncated = false;
    }

    private function updateHistoryQuery(bool $includeOutputTail = false): Builder
    {
        $table = (new AppUpdate)->getTable();
        $outputLengthSql = "LENGTH(COALESCE({$table}.output_log, ''))";

        $columns = [
            "{$table}.id",
            "{$table}.status",
            "{$table}.from_hash",
            "{$table}.to_hash",
            "{$table}.started_at",
            "{$table}.finished_at",
            DB::raw("{$outputLengthSql} as output_log_length"),
        ];

        if ($includeOutputTail) {
            $tailChars = self::OUTPUT_LOG_TAIL_CHARS;
            $columns[] = DB::raw(
                "CASE ".
                "WHEN {$outputLengthSql} = 0 THEN NULL ".
                "ELSE SUBSTR({$table}.output_log, CASE WHEN {$outputLengthSql} > {$tailChars} THEN {$outputLengthSql} - {$tailChars} + 1 ELSE 1 END) ".
                'END as output_log_tail'
            );
        }

        return AppUpdate::query()
            ->select($columns)
            ->orderByDesc("{$table}.started_at");
    }
}
