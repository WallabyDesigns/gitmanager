<?php

namespace App\Livewire\System;

use App\Models\DeploymentQueueItem;
use App\Models\User;
use App\Services\DeploymentQueueService;
use App\Services\EditionService;
use App\Services\EnvBackupService;
use App\Services\EnvManagerService;
use App\Services\LicenseService;
use App\Services\LogCleanupService;
use App\Services\SchedulerService;
use App\Services\SettingsService;
use App\Support\InstallContext;
use App\Support\SchedulerTaskIntervals;
use Composer\InstalledVersions;
use Livewire\Component;

class Settings extends Component
{
    public const SECTION_SCHEDULER = 'scheduler';
    public const SECTION_APPLICATION = 'application';
    public const SECTION_AUDITS = 'audits';
    public const SECTION_LICENSING = 'licensing';
    public const SECTION_ENVIRONMENT = 'environment';
    // Legacy alias retained so older links continue to work.
    public const SECTION_REGIONAL = 'regional';

    public bool $checkUpdates = true;
    public bool $autoUpdate = true;
    public bool $githubSslVerify = true;
    public bool $healthEmailEnabled = true;
    public bool $auditEnabled = false;
    public bool $auditEmailEnabled = false;
    public bool $auditAutoCommit = false;
    public bool $mailConfigured = false;
    public string $edition = EditionService::COMMUNITY;
    public bool $canSwapEditions = false;
    public bool $isEnterprise = false;
    public string $enterpriseLicenseKey = '';
    public array $licenseState = [];
    public string $timezone = '';
    public array $timezones = [];
    public string $settingsSection = self::SECTION_SCHEDULER;
    public bool $isLocalInstall = false;
    public bool $localLicenseTlsBypassEnabled = false;
    public string $systemPackageVersion = 'Unknown';
    /** @var array<string, array{value: int, unit: string}> */
    public array $schedulerTaskIntervals = [];
    public bool $logCleanupEnabled = false;
    public int $logRetentionDays = LogCleanupService::DEFAULT_RETENTION_DAYS;

    public bool $loaded = false;

    /** @var array<string, array{key: string, value: string, description: string}> */
    public array $gwmKeys = [];
    /** @var array<string, string> */
    public array $gwmEdits = [];
    /** @var array<int, array{filename: string, created_at: string, size: int, label: string}> */
    public array $envBackups = [];
    public string $envBackupLabel = '';
    public bool $envSaveSuccess = false;

    public function mount(): void
    {
        $this->settingsSection = $this->resolveRequestedSection();
    }

    public function loadData(EditionService $edition, SettingsService $settings, LicenseService $license, EnvManagerService $envManager, EnvBackupService $backupService): void
    {
        $this->checkUpdates = (bool) ($settings->get('system.check_updates', true));
        $this->autoUpdate = (bool) ($settings->get('system.auto_update', (bool) config('gitmanager.self_update.enabled', true)));
        $this->githubSslVerify = (bool) ($settings->get(
            'system.github_ssl_verify',
            (bool) config('services.github.verify_ssl', true)
        ));
        $this->healthEmailEnabled = (bool) ($settings->get('system.health_email_enabled', true));
        $this->auditEnabled = (bool) ($settings->get('system.audit_enabled', false));
        $this->auditEmailEnabled = (bool) ($settings->get('system.audit_email_enabled', false));
        $this->auditAutoCommit = (bool) ($settings->get('system.audit_auto_commit', false));
        $this->mailConfigured = $settings->isMailConfigured();
        $this->edition = $edition->current();
        $this->canSwapEditions = $edition->canSwapForTesting();
        $this->isEnterprise = $this->edition === EditionService::ENTERPRISE;
        if (! $this->isEnterprise) {
            $this->auditEnabled = false;
            $this->auditEmailEnabled = false;
            $this->auditAutoCommit = false;
        }
        $this->licenseState = $license->state();
        $this->isLocalInstall = $this->detectLocalInstall();
        $this->localLicenseTlsBypassEnabled = (bool) $settings->get('system.license.allow_insecure_local_tls', false);
        $this->systemPackageVersion = $this->resolveSystemPackageVersion();
        $this->schedulerTaskIntervals = SchedulerTaskIntervals::normalize(
            $settings->get(SchedulerTaskIntervals::SETTINGS_KEY, [])
        );
        $this->logCleanupEnabled = (bool) $settings->get('system.logs.cleanup_enabled', false);
        $this->logRetentionDays = LogCleanupService::normalizeRetentionDays(
            $settings->get('system.logs.retention_days', LogCleanupService::DEFAULT_RETENTION_DAYS)
        );

        $this->timezones = \DateTimeZone::listIdentifiers();
        $stored = (string) ($settings->get('system.timezone') ?? '');
        if ($stored === '') {
            $stored = (string) (User::query()->where('id', 1)->value('timezone') ?? '');
        }
        if ($stored === '') {
            $stored = (string) config('app.timezone');
        }
        $this->timezone = $stored;

        if ($this->settingsSection === self::SECTION_ENVIRONMENT) {
            $this->loadEnvironmentSection($envManager, $backupService);
        }

        $this->loaded = true;
    }

    public function render(EditionService $edition, SchedulerService $scheduler): \Illuminate\View\View
    {
        $schedulerGraceSeconds = max(600, (int) config('gitmanager.scheduler.stale_seconds', 600));

        return view('livewire.system.settings', [
            'schedulerHealthy' => $scheduler->isHealthy($schedulerGraceSeconds),
            'lastHeartbeat' => $scheduler->lastHeartbeat(),
            'lastManualRun' => $scheduler->lastManualRun(),
            'lastSource' => $scheduler->lastSource(),
            'schedulerLog' => $scheduler->schedulerLogEntries(),
            'queueEnabled' => config('gitmanager.deploy_queue.enabled', true),
            'queueCount' => DeploymentQueueItem::query()
                ->where('status', 'queued')
                ->count(),
            'cronCommand' => $scheduler->cronCommand(),
            'editionLabel' => $edition->label(),
            'schedulerTaskDefinitions' => SchedulerTaskIntervals::definitions(),
            'schedulerTaskUnitOptions' => SchedulerTaskIntervals::unitOptions(),
            'schedulerTaskStatuses' => $this->schedulerTaskStatuses(),
        ])
            ->layout('layouts.app', [
                'title' => 'System Settings',
                'header' => view('livewire.system.partials.header', [
                    'title' => 'System',
                    'subtitle' => 'Manage app updates, security checks, settings, and email.',
                ]),
            ]);
    }

    public function refreshSchedulerStatus(): void
    {
        $this->dispatch('$refresh');
    }

    public function selectSettingsSection(string $section, EnvManagerService $envManager = null, EnvBackupService $backupService = null): void
    {
        $this->settingsSection = $this->normalizeSection($section);

        if ($this->settingsSection === self::SECTION_ENVIRONMENT && $envManager && $backupService) {
            $this->loadEnvironmentSection($envManager, $backupService);
        }
    }

    public function runScheduler(SchedulerService $scheduler): void
    {
        $result = $scheduler->runScheduleNow();
        $scheduler->recordManualRun();
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function processQueue(DeploymentQueueService $queue, SchedulerService $scheduler): void
    {
        $processed = $queue->processNext((int) config('gitmanager.deploy_queue.batch_size', 0));
        $scheduler->recordManualRun();
        $scheduler->recordHeartbeat('manual');

        if ($processed === 0) {
            $this->dispatch('notify', message: 'No queued items to process.');
            $this->dispatch('$refresh');
            return;
        }

        $this->dispatch('notify', message: "Processed {$processed} queued item(s).");
        $this->dispatch('$refresh');
    }

    public function installCron(SchedulerService $scheduler): void
    {
        $result = $scheduler->installCron();
        $message = $result['message'] ?? 'Cron action completed.';
        $this->dispatch('notify', message: $message);
        $this->dispatch('$refresh');
    }

    public function runLogCleanup(LogCleanupService $cleanup): void
    {
        $this->logRetentionDays = LogCleanupService::normalizeRetentionDays($this->logRetentionDays);
        $result = $cleanup->cleanupOlderThanDays($this->logRetentionDays);
        $this->dispatch('notify', message: $this->formatLogCleanupMessage($result, false));
        $this->dispatch('$refresh');
    }

    public function clearAllStoredLogs(LogCleanupService $cleanup): void
    {
        $result = $cleanup->clearAll(false, true);
        $this->dispatch('notify', message: $this->formatLogCleanupMessage($result, true));
        $this->dispatch('$refresh');
    }

    public function verifyLicense(LicenseService $license, EditionService $edition): void
    {
        if (trim($this->enterpriseLicenseKey) !== '') {
            $license->setLicenseKey($this->enterpriseLicenseKey);
            $this->enterpriseLicenseKey = '';
        }

        $this->licenseState = $license->verifyNow();
        $this->edition = $edition->current();
        $this->isEnterprise = $this->edition === EditionService::ENTERPRISE;
        if (! $this->isEnterprise) {
            $this->auditEnabled = false;
            $this->auditEmailEnabled = false;
            $this->auditAutoCommit = false;
        }
        $this->dispatch('notify', message: (string) ($this->licenseState['message'] ?? 'License verification completed.'));
        $this->dispatch('$refresh');
    }

    public function clearLicense(LicenseService $license, EditionService $edition): void
    {
        $license->clearLicense();
        $this->enterpriseLicenseKey = '';
        $this->licenseState = $license->state();
        $this->edition = $edition->current();
        $this->isEnterprise = $this->edition === EditionService::ENTERPRISE;
        $this->auditEnabled = false;
        $this->auditEmailEnabled = false;
        $this->auditAutoCommit = false;
        $this->dispatch('notify', message: 'Enterprise license cleared.');
        $this->dispatch('$refresh');
    }

    public function runLocalLicenseRepair(LicenseService $license, EditionService $edition, SettingsService $settings): void
    {
        if (! $this->detectLocalInstall()) {
            $this->dispatch('notify', message: 'Best-effort local repair is only available for local/testing installs.');
            return;
        }

        $settings->set('system.license.allow_insecure_local_tls', true);
        $this->localLicenseTlsBypassEnabled = true;

        $this->licenseState = $license->verifyNow();
        $this->edition = $edition->current();
        $this->isEnterprise = $this->edition === EditionService::ENTERPRISE;
        if (! $this->isEnterprise) {
            $this->auditEnabled = false;
            $this->auditEmailEnabled = false;
            $this->auditAutoCommit = false;
        }

        if (($this->licenseState['status'] ?? '') === 'valid') {
            $this->dispatch('notify', message: 'SSL repair complete — license verified successfully.');
            $this->dispatch('$refresh');
            return;
        }

        $newMessage = strtolower(trim((string) ($this->licenseState['message'] ?? '')));

        $statusMessage = (string) ($this->licenseState['message'] ?? 'License verification still failed.');
        $this->dispatch('notify', message: 'TLS bypass enabled. Verification still failed: '.$statusMessage);
        $this->dispatch('$refresh');
    }

    public function save(EditionService $edition, SettingsService $settings, LicenseService $license): void
    {
        $rules = [
            'timezone' => ['required', \Illuminate\Validation\Rule::in($this->timezones)],
            'enterpriseLicenseKey' => ['nullable', 'string', 'max:255'],
            'schedulerTaskIntervals' => ['required', 'array'],
            'logCleanupEnabled' => ['boolean'],
            'logRetentionDays' => ['required', 'integer', 'min:1', 'max:'.LogCleanupService::MAX_RETENTION_DAYS],
        ];

        foreach (SchedulerTaskIntervals::definitions() as $task => $definition) {
            $rules["schedulerTaskIntervals.{$task}.value"] = ['required', 'integer', 'min:1', 'max:59'];
            $rules["schedulerTaskIntervals.{$task}.unit"] = ['required', \Illuminate\Validation\Rule::in(array_keys(SchedulerTaskIntervals::unitOptions()))];
        }

        if ($this->canSwapEditions) {
            $rules['edition'] = ['required', \Illuminate\Validation\Rule::in([
                EditionService::COMMUNITY,
                EditionService::ENTERPRISE,
            ])];
        }

        $this->validate($rules);

        foreach (SchedulerTaskIntervals::definitions() as $task => $definition) {
            $unit = SchedulerTaskIntervals::normalizeUnit(
                $this->schedulerTaskIntervals[$task]['unit'] ?? null,
                (string) $definition['default_unit']
            );
            $value = (int) ($this->schedulerTaskIntervals[$task]['value'] ?? $definition['default_value']);

            if ($unit === 'hours' && $value > 24) {
                $this->addError(
                    "schedulerTaskIntervals.{$task}.value",
                    "The {$definition['label']} frequency may not exceed 24 hours."
                );
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->schedulerTaskIntervals = SchedulerTaskIntervals::normalize($this->schedulerTaskIntervals);
        $this->logRetentionDays = LogCleanupService::normalizeRetentionDays($this->logRetentionDays);

        if (trim($this->enterpriseLicenseKey) !== '') {
            $license->setLicenseKey($this->enterpriseLicenseKey);
            $this->enterpriseLicenseKey = '';
        }
        if ($this->canSwapEditions) {
            $edition->setTestingEdition($this->edition);
        } else {
            $this->edition = $edition->current();
        }

        $this->edition = $edition->current();
        $this->isEnterprise = $this->edition === EditionService::ENTERPRISE;
        if (! $this->isEnterprise) {
            $this->auditEnabled = false;
            $this->auditEmailEnabled = false;
            $this->auditAutoCommit = false;
        }

        $settings->set('system.check_updates', $this->checkUpdates);
        $settings->set('system.auto_update', $this->autoUpdate);
        $settings->set('system.github_ssl_verify', $this->githubSslVerify);
        $settings->set('system.health_email_enabled', $this->healthEmailEnabled);
        $settings->set('system.audit_enabled', $this->auditEnabled);
        $settings->set('system.audit_email_enabled', $this->auditEmailEnabled);
        $settings->set('system.audit_auto_commit', $this->auditAutoCommit);
        $settings->set('system.timezone', $this->timezone);
        $settings->set(SchedulerTaskIntervals::SETTINGS_KEY, $this->schedulerTaskIntervals);
        $settings->set('system.logs.cleanup_enabled', $this->logCleanupEnabled);
        $settings->set('system.logs.retention_days', $this->logRetentionDays);

        $this->licenseState = $license->state();

        if (User::query()->where('id', 1)->exists()) {
            User::query()->where('id', 1)->update(['timezone' => $this->timezone]);
        }

        $this->dispatch('notify', message: 'System settings saved.');
        $this->dispatch('settings-saved');
    }

    private function resolveRequestedSection(): string
    {
        // Legacy query-string navigation support:
        // /system/settings?section=application
        $querySection = trim((string) request()->query('section', ''));
        if ($querySection !== '') {
            return $this->normalizeSection($querySection);
        }

        $routeName = (string) optional(request()->route())->getName();

        return match ($routeName) {
            'system.application' => self::SECTION_APPLICATION,
            'system.audits' => self::SECTION_AUDITS,
            'system.licensing' => self::SECTION_LICENSING,
            'system.scheduler' => self::SECTION_SCHEDULER,
            'system.environment' => self::SECTION_ENVIRONMENT,
            default => self::SECTION_SCHEDULER,
        };
    }

    private function normalizeSection(string $section): string
    {
        $normalized = strtolower(trim($section));
        if ($normalized === self::SECTION_REGIONAL) {
            $normalized = self::SECTION_APPLICATION;
        }

        $allowed = [
            self::SECTION_SCHEDULER,
            self::SECTION_APPLICATION,
            self::SECTION_AUDITS,
            self::SECTION_LICENSING,
            self::SECTION_ENVIRONMENT,
        ];

        return in_array($normalized, $allowed, true)
            ? $normalized
            : self::SECTION_SCHEDULER;
    }

    public function loadEnvironmentSection(EnvManagerService $envManager, EnvBackupService $backupService): void
    {
        $this->gwmKeys = $envManager->getGwmKeys();
        $this->gwmEdits = array_map(fn ($k) => $k['value'], $this->gwmKeys);
        $this->envBackups = $backupService->list();
    }

    public function saveEnvKey(string $key, EnvBackupService $backupService, EnvManagerService $envManager): void
    {
        if (! str_starts_with($key, 'GWM_') || in_array($key, EnvManagerService::HIDDEN_KEYS, true)) {
            return;
        }

        $value = (string) ($this->gwmEdits[$key] ?? '');

        try {
            $backupService->backup('settings-edit');
        } catch (\Throwable) {
        }

        $envManager->set($key, $value);
        $this->gwmKeys = $envManager->getGwmKeys();
        $this->gwmEdits = array_map(fn ($k) => $k['value'], $this->gwmKeys);
        $this->envSaveSuccess = true;
        $this->dispatch('notify', message: "{$key} updated.");
    }

    public function createEnvBackup(EnvBackupService $backupService): void
    {
        try {
            $label = trim($this->envBackupLabel);
            $backupService->backup($label !== '' ? $label : 'manual');
            $this->envBackupLabel = '';
            $this->envBackups = $backupService->list();
            $this->dispatch('notify', message: 'Environment backup created.');
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: 'Backup failed: '.$e->getMessage());
        }
    }

    public function restoreEnvBackup(string $filename, EnvBackupService $backupService): void
    {
        try {
            $backupService->restore($filename);
            $this->envBackups = $backupService->list();
            $this->dispatch('notify', message: 'Environment restored from backup.');
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: 'Restore failed: '.$e->getMessage());
        }
    }

    public function deleteEnvBackup(string $filename, EnvBackupService $backupService): void
    {
        try {
            $backupService->delete($filename);
            $this->envBackups = $backupService->list();
            $this->dispatch('notify', message: 'Backup deleted.');
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: 'Delete failed: '.$e->getMessage());
        }
    }

    private function detectLocalInstall(): bool
    {
        return InstallContext::isLocalInstall();
    }

    /**
     * @return array<string, array{enabled: bool, label: string}>
     */
    private function schedulerTaskStatuses(): array
    {
        $selfUpdateAvailable = (bool) config('gitmanager.self_update.enabled', true);

        return [
            'project_health_checks' => [
                'enabled' => true,
                'label' => 'Enabled',
            ],
            'queue_processing' => [
                'enabled' => (bool) config('gitmanager.deploy_queue.enabled', true),
                'label' => (bool) config('gitmanager.deploy_queue.enabled', true) ? 'Enabled' : 'Disabled',
            ],
            'self_audit' => [
                'enabled' => true,
                'label' => 'Enabled',
            ],
            'self_update' => [
                'enabled' => $selfUpdateAvailable && $this->autoUpdate,
                'label' => $selfUpdateAvailable && $this->autoUpdate ? 'Enabled' : 'Disabled',
            ],
        ];
    }

    private function resolveSystemPackageVersion(): string
    {
        $packageName = (string) config('gitmanager.enterprise.package_name', 'wallabydesigns/gitmanager-enterprise');
        if ($packageName === '' || ! class_exists(InstalledVersions::class)) {
            return 'Unknown';
        }

        try {
            if (! InstalledVersions::isInstalled($packageName)) {
                return 'Not installed';
            }

            $version = InstalledVersions::getPrettyVersion($packageName)
                ?? InstalledVersions::getVersion($packageName);

            $version = trim((string) $version);

            return $version !== '' ? $version : 'Unknown';
        } catch (\Throwable) {
            return 'Unknown';
        }
    }

    private function formatLogCleanupMessage(array $result, bool $clearedAll): string
    {
        $records = (int) ($result['total_records'] ?? 0);
        $scope = $clearedAll
            ? 'stored logs'
            : 'stored logs older than '.$this->logRetentionDays.' day(s)';

        $message = "Cleared {$records} {$scope}.";

        if (($result['vacuumed'] ?? false) === true) {
            $message .= ' SQLite VACUUM completed.';
        }

        return $message;
    }
}
