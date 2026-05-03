<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use Illuminate\Console\Command;

class VerifyEnterpriseLicense extends Command
{
    protected $signature = 'license:verify';

    protected $description = 'Register Community installs and verify configured Enterprise licenses.';

    public function handle(LicenseService $license): int
    {
        $state = $license->verifyNow();
        $status = (string) ($state['status'] ?? 'invalid');
        $message = (string) ($state['message'] ?? '');
        $edition = (string) ($state['edition'] ?? 'community');

        if ($status === 'valid' && strtolower($edition) === 'enterprise') {
            $this->info($message !== '' ? $message : 'Enterprise license is valid.');

            return self::SUCCESS;
        }

        if (! $license->keyConfigured()) {
            $this->line($message !== '' ? $message : 'Community installation registration checked.');

            return self::SUCCESS;
        }

        if ($status === 'valid' && strtolower($edition) === 'community') {
            $this->info($message !== '' ? $message : 'Community installation is registered.');

            return self::SUCCESS;
        }

        $this->warn($message !== '' ? $message : 'Enterprise license verification failed.');

        return self::FAILURE;
    }
}
