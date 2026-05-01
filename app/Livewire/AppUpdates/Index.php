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

    public string $activeTab = 'status';

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
        $recent = $this->updateHistoryQuery()->take(25)->get();

        return view('livewire.app-updates.index', [
            'latest' => $this->latestUpdate(),
            'latestDependencyLog' => $this->latestDependencyLog(),
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

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['status', 'dependencies', 'logs'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->resetExpandedUpdateLog();
    }

    public function runUpdate(SelfUpdateService $service): void
    {
        $result = $service->startUpdateInBackground(Auth::user());
        $message = $result['message'];

        $this->updateStatus = $service->getUpdateStatus(true);
        $this->loadPendingChanges($service);
        $this->resetExpandedUpdateLog();
        $this->dispatch('notify', message: $message, type: ($result['ok'] ?? false) ? 'success' : 'error');
        $this->redirectRoute('system.updates', navigate: false);
    }

    public function runForceUpdate(SelfUpdateService $service): void
    {
        $result = $service->startUpdateInBackground(Auth::user(), true);
        $message = $result['message'];

        $this->updateStatus = $service->getUpdateStatus(true);
        $this->loadPendingChanges($service);
        $this->resetExpandedUpdateLog();
        $this->dispatch('notify', message: $message, type: ($result['ok'] ?? false) ? 'success' : 'error');
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

    public function auditAppDependencies(SelfUpdateService $service): void
    {
        $update = $service->auditAppDependencies(Auth::user());
        $this->dispatch('notify', message: match ($update->status) {
            'success' => 'App dependency audit passed.',
            'warning' => 'App dependency audit found issues. Review the logs.',
            default => 'App dependency audit failed. Review the logs.',
        });
        $this->resetExpandedUpdateLog();
    }

    public function updateAppComposer(SelfUpdateService $service): void
    {
        $update = $service->updateAppComposerDependencies(Auth::user());
        $this->dispatch('notify', message: $update->status === 'success'
            ? 'App Composer dependencies updated.'
            : 'App Composer update failed. Review the logs.');
        $this->resetExpandedUpdateLog();
    }

    public function updateAppNpm(SelfUpdateService $service): void
    {
        $update = $service->updateAppNpmDependencies(Auth::user());
        $this->dispatch('notify', message: $update->status === 'success'
            ? 'App npm dependencies updated.'
            : 'App npm update failed. Review the logs.');
        $this->resetExpandedUpdateLog();
    }

    public function fixAppNpmAudit(SelfUpdateService $service): void
    {
        $update = $service->fixAppNpmAudit(Auth::user(), false);
        $this->dispatch('notify', message: $update->status === 'success'
            ? 'App npm audit fix completed.'
            : 'App npm audit fix failed. Review the logs.');
        $this->resetExpandedUpdateLog();
    }

    public function toggleUpdateLog(int $updateId): void
    {
        $this->activeTab = 'logs';

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
        return $this->updateHistoryQuery(includeOutputTail: true)
            ->whereIn('action', ['self_update', 'force_update', 'rollback'])
            ->first();
    }

    private function latestDependencyLog(): ?AppUpdate
    {
        return $this->updateHistoryQuery(includeOutputTail: true)
            ->whereIn('action', $this->dependencyActions())
            ->first();
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
            "{$table}.action",
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
                'CASE '.
                "WHEN {$outputLengthSql} = 0 THEN NULL ".
                "ELSE SUBSTR({$table}.output_log, CASE WHEN {$outputLengthSql} > {$tailChars} THEN {$outputLengthSql} - {$tailChars} + 1 ELSE 1 END) ".
                'END as output_log_tail'
            );
        }

        return AppUpdate::query()
            ->select($columns)
            ->orderByDesc("{$table}.started_at");
    }

    /**
     * @return array<int, string>
     */
    private function dependencyActions(): array
    {
        return [
            'app_dependency_audit',
            'app_composer_update',
            'app_npm_update',
            'app_npm_audit_fix',
            'app_npm_audit_fix_force',
        ];
    }
}
