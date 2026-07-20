<?php

namespace App\Services;

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

class RuntimePluginStatusService
{
    /**
     * @return array{installed: bool, configured: bool, version: ?string, message: string}
     */
    public function reverb(): array
    {
        $installed = class_exists('Laravel\\Reverb\\ReverbServiceProvider');
        $configured = config('broadcasting.default') === 'reverb';

        if (! $installed) {
            return [
                'installed' => false,
                'configured' => false,
                'version' => null,
                'message' => __('Laravel Reverb is not installed in this application build.'),
            ];
        }

        return [
            'installed' => true,
            'configured' => $configured,
            'version' => $this->composerVersion('laravel/reverb'),
            'message' => $configured
                ? __('Laravel Reverb is installed and selected for broadcasting.')
                : __('Laravel Reverb is installed but is not the active broadcast connection.'),
        ];
    }

    /**
     * @return array{installed: bool, version: ?string, binary: ?string, message: string}
     */
    public function rustExecutor(): array
    {
        $binary = trim((string) config('gitmanager.rust_executor.binary', ''));
        if ($binary === '') {
            return [
                'installed' => false,
                'version' => null,
                'binary' => null,
                'message' => __('The managed Rust executor is not installed. It will be optional when released; PHP workers remain the fallback.'),
            ];
        }

        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful()) {
                return [
                    'installed' => true,
                    'version' => trim($process->getOutput()) ?: null,
                    'binary' => $binary,
                    'message' => __('The managed Rust executor is available for process workloads.'),
                ];
            }
        } catch (\Throwable) {
            // Treat an unavailable configured executable as not installed.
        }

        return [
            'installed' => false,
            'version' => null,
            'binary' => $binary,
            'message' => __('The configured Rust executor binary could not be started.'),
        ];
    }

    private function composerVersion(string $package): ?string
    {
        return InstalledVersions::isInstalled($package)
            ? InstalledVersions::getPrettyVersion($package)
            : null;
    }
}
