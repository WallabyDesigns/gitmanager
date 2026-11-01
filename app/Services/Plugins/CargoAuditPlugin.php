<?php

namespace App\Services\Plugins;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class CargoAuditPlugin implements ManagedPlugin
{
    public function slug(): string
    {
        return 'cargo-audit';
    }

    public function displayName(): string
    {
        return 'Cargo Audit';
    }

    public function description(): string
    {
        return 'RustSec vulnerability scanner used for dependency audits of Rust (Cargo) projects. Requires the Rust Toolchain plugin (or cargo on PATH) to install.';
    }

    public function category(): string
    {
        return 'runtime';
    }

    public function installDir(): string
    {
        return storage_path('plugins/cargo-audit');
    }

    private function binaryName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'cargo-audit.exe' : 'cargo-audit';
    }

    /**
     * Plugin-managed binary first, then any cargo-audit already on PATH.
     */
    public function binaryPath(): ?string
    {
        $managed = $this->installDir().DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.$this->binaryName();
        if (is_file($managed)) {
            return $managed;
        }

        return (new ExecutableFinder)->find('cargo-audit');
    }

    public function isInstalled(): bool
    {
        return $this->binaryPath() !== null;
    }

    public function installedVersion(): ?string
    {
        $binary = $this->binaryPath();
        if ($binary === null) {
            return null;
        }

        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful() && preg_match('/(\d+\.\d+\.\d+)/', $process->getOutput(), $m)) {
                return $m[1];
            }
        } catch (\Throwable) {
            // Fall through.
        }

        return null;
    }

    public function fetchLatestVersion(): ?string
    {
        try {
            $ctx = stream_context_create(['http' => [
                'timeout' => 10,
                'header' => "User-Agent: git-web-manager\r\n",
            ]]);
            $json = @file_get_contents('https://crates.io/api/v1/crates/cargo-audit', false, $ctx);
            $data = $json === false ? null : json_decode($json, true);

            return isset($data['crate']['max_stable_version']) ? (string) $data['crate']['max_stable_version'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function install(): array
    {
        return $this->cargoInstall('installed');
    }

    public function update(): array
    {
        return $this->cargoInstall('updated');
    }

    public function uninstall(): array
    {
        $dir = $this->installDir();
        if (! is_dir($dir)) {
            return ['success' => true, 'message' => 'Cargo Audit is not installed by the app — nothing to remove.'];
        }

        try {
            $this->deleteDirectory($dir);

            return ['success' => true, 'message' => 'Cargo Audit removed successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to remove Cargo Audit: '.$e->getMessage()];
        }
    }

    public function checkVulnerabilities(): array
    {
        return [];
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function cargoInstall(string $verb): array
    {
        $rust = app(RustToolchainPlugin::class);
        $cargo = $rust->binaryPath();
        if ($cargo === null) {
            return ['success' => false, 'message' => 'A Rust toolchain (cargo) is required to install cargo-audit. Install the Rust Toolchain plugin first.'];
        }

        $dir = $this->installDir();
        if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return ['success' => false, 'message' => 'Could not create install directory: '.$dir];
        }

        try {
            $process = new Process([$cargo, 'install', 'cargo-audit', '--locked', '--force', '--root', $dir], null, $rust->processEnv());
            $process->setTimeout(1200);
            $process->run();

            if (! $process->isSuccessful()) {
                $tail = implode("\n", array_slice(array_filter(explode("\n", trim($process->getErrorOutput()))), -5));

                return ['success' => false, 'message' => 'cargo install failed: '.$tail];
            }

            return ['success' => true, 'message' => 'Cargo Audit '.$verb.' successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Cargo Audit install error: '.$e->getMessage()];
        }
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
