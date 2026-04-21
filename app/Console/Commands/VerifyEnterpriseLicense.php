<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use Illuminate\Console\Command;

class VerifyEnterpriseLicense extends Command
{
    protected $signature = 'license:verify';

    protected $description = 'Verify the configured enterprise license with the license server.';

    public function handle(LicenseService $license): int
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

            return self::SUCCESS;
        }

        $this->warn($message !== '' ? $message : 'Enterprise license verification failed.');

        return self::FAILURE;
    }
}
