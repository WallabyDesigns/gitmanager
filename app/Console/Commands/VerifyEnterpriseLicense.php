<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use App\Services\SelfUpdateService;
use Illuminate\Console\Command;

class VerifyEnterpriseLicense extends Command
{
    protected $signature = 'license:verify';

    protected $description = 'Verify the configured enterprise license with the license server.';

    public function handle(LicenseService $license, SelfUpdateService $updater): int
    {
        if (! $license->keyConfigured()) {
            $this->line('No enterprise license key configured. Skipping verification.');

            return self::SUCCESS;
        }

        $state = $license->verifyNow();
        $status = (string) ($state['status'] ?? 'invalid');
        $message = (string) ($state['message'] ?? '');
        $edition = (string) ($state['edition'] ?? 'community');

        if ($status === 'valid' && strtolower($edition) === 'enterprise') {
            $this->info($message !== '' ? $message : 'Enterprise license is valid.');
            $this->maybeInstallEnterprisePackage($updater);

            return self::SUCCESS;
        }

        $this->warn($message !== '' ? $message : 'Enterprise license verification failed.');

        return self::FAILURE;
    }

    private function maybeInstallEnterprisePackage(SelfUpdateService $updater): void
    {
        $repoPath = base_path();
        $output = [];
        $packageStatus = $updater->getEnterprisePackageStatus($repoPath, $output);

        if (! in_array($packageStatus['status'] ?? '', ['not-installed', 'update-available'], true)) {
            return;
        }

        $updater->installOrUpdateEnterprisePackage($repoPath, $output, $packageStatus);

        foreach ($output as $line) {
            $this->line($line);
        }
    }
}
