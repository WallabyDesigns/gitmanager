<?php

declare(strict_types=1);

namespace App\Livewire\System;

use App\Services\EnvBackupService;
use App\Services\EnvManagerService;
use App\Services\LicenseService;
use Livewire\Component;

class EnvMigration extends Component
{
    public int $step = 1;

    /** @var array<string, array{key: string, default: string, description: string}> */
    public array $missingKeys = [];

    /** @var array<string, string> */
    public array $values = [];

    public function mount(EnvManagerService $envManager): void
    {
        $this->missingKeys = $envManager->getMissingKeys();

        foreach ($this->missingKeys as $key => $meta) {
            $this->values[$key] = $meta['default'];
        }

        if (empty($this->missingKeys)) {
            $this->step = 2;
        }
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.system.env-migration')
            ->layout('layouts.app', [
                'title' => 'Environment Setup',
                'header' => null,
            ]);
    }

    public function saveValues(EnvBackupService $backup, EnvManagerService $envManager, LicenseService $license): void
    {
        try {
            $backup->backup('pre-migration');
        } catch (\Throwable) {
        }

        $toWrite = [];

        foreach ($this->values as $key => $value) {
            $toWrite[$key] = (string) $value;
        }

        if (! empty($toWrite)) {
            $envManager->setMany($toWrite);
        }

        // Silently re-verify the license after env changes — no result shown to user.
        try {
            $license->verifyNow();
        } catch (\Throwable) {
        }

        $this->missingKeys = [];
        $this->step = 2;
    }

    public function finish(): void
    {
        session()->forget('env_migration_dismissed');

        $this->redirect(route('system.scheduler'), navigate: true);
    }

    public function dismiss(): void
    {
        session()->put('env_migration_dismissed', true);

        $this->redirect(route('projects.index'), navigate: true);
    }
}
