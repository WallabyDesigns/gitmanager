<?php

namespace App\Livewire\System;

use App\Models\DeploymentQueueItem;
use App\Models\User;
use App\Services\DeploymentQueueService;
use App\Services\EditionService;
use App\Services\LicenseService;
use App\Services\SchedulerService;
use App\Services\SettingsService;
use Livewire\Component;

class Settings extends Component
{
    public const SECTION_SCHEDULER = 'scheduler';
    public const SECTION_APPLICATION = 'application';
    public const SECTION_AUDITS = 'audits';
    public const SECTION_LICENSING = 'licensing';
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

    public function mount(EditionService $edition, SettingsService $settings, LicenseService $license): void
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

        $this->timezones = \DateTimeZone::listIdentifiers();
        $stored = (string) ($settings->get('system.timezone') ?? '');
        if ($stored === '') {
            $stored = (string) (User::query()->where('id', 1)->value('timezone') ?? '');
        }
        if ($stored === '') {
            $stored = (string) config('app.timezone');
        }
        $this->timezone = $stored;

        $requestedSection = $this->resolveRequestedSection();
        $this->selectSettingsSection($requestedSection);
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

    public function selectSettingsSection(string $section): void
    {
        $this->settingsSection = $this->normalizeSection($section);
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
        $processed = $queue->processNext(3);
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
        ];

        if ($this->canSwapEditions) {
            $rules['edition'] = ['required', \Illuminate\Validation\Rule::in([
                EditionService::COMMUNITY,
                EditionService::ENTERPRISE,
            ])];
        }

        $this->validate($rules);

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
        ];

        return in_array($normalized, $allowed, true)
            ? $normalized
            : self::SECTION_SCHEDULER;
    }

    private function detectLocalInstall(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return str_ends_with($host, '.local') || str_ends_with($host, '.test');
    }
}
