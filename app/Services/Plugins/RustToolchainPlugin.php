<?php

namespace App\Services\Plugins;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class RustToolchainPlugin implements ManagedPlugin
{
    private const DIST_URL = 'https://static.rust-lang.org/rustup/dist';

    public function slug(): string
    {
        return 'rust-toolchain';
    }

    public function displayName(): string
    {
        return 'Rust Toolchain';
    }

    public function description(): string
    {
        return 'Minimal Rust toolchain (rustc and cargo, installed with rustup from static.rust-lang.org) used to build Rust tools such as cargo-audit. Linux only. Building crates needs a C linker (build-essential).';
    }

    public function category(): string
    {
        return 'runtime';
    }

    public function installDir(): string
    {
        return storage_path('plugins/rust');
    }

    private function cargoHome(): string
    {
        return $this->installDir().DIRECTORY_SEPARATOR.'cargo';
    }

    private function rustupHome(): string
    {
        return $this->installDir().DIRECTORY_SEPARATOR.'rustup';
    }

    /**
     * Plugin-managed cargo first, then any cargo already on PATH.
     */
    public function binaryPath(): ?string
    {
        $managed = $this->cargoHome().DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'cargo';
        if (is_file($managed)) {
            return $managed;
        }

        return (new ExecutableFinder)->find('cargo');
    }

    /**
     * Environment needed to run the managed toolchain; harmless for a system cargo.
     *
     * @return array<string, string>
     */
    public function processEnv(): array
    {
        return [
            'CARGO_HOME' => $this->cargoHome(),
            'RUSTUP_HOME' => $this->rustupHome(),
            'PATH' => $this->cargoHome().DIRECTORY_SEPARATOR.'bin'.PATH_SEPARATOR.(getenv('PATH') ?: ''),
        ];
    }

    public function isInstalled(): bool
    {
        return $this->binaryPath() !== null;
    }

    public function installedVersion(): ?string
    {
        $cargo = $this->binaryPath();
        if ($cargo === null) {
            return null;
        }

        try {
            $process = new Process([$cargo, '--version'], null, $this->processEnv());
            $process->setTimeout(15);
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
            $json = @file_get_contents('https://api.github.com/repos/rust-lang/rust/releases/latest', false, $ctx);
            $data = $json === false ? null : json_decode($json, true);

            return isset($data['tag_name']) ? ltrim((string) $data['tag_name'], 'v') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function install(): array
    {
        $triple = $this->hostTriple();
        if ($triple === null) {
            return ['success' => false, 'message' => 'Automatic Rust installation is only supported on Linux (x86_64 or aarch64). Install Rust manually on this host.'];
        }

        $dir = $this->installDir();
        if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return ['success' => false, 'message' => 'Could not create install directory: '.$dir];
        }

        $installer = $dir.DIRECTORY_SEPARATOR.'rustup-init';

        try {
            $download = $this->downloadVerified(self::DIST_URL.'/'.$triple.'/rustup-init', $installer);
            if ($download !== null) {
                return ['success' => false, 'message' => $download];
            }

            @chmod($installer, 0755);

            $process = new Process(
                [$installer, '-y', '--profile', 'minimal', '--no-modify-path', '--default-toolchain', 'stable'],
                $dir,
                $this->processEnv()
            );
            $process->setTimeout(1200);
            $process->run();

            if (! $process->isSuccessful()) {
                $tail = implode("\n", array_slice(array_filter(explode("\n", trim($process->getErrorOutput()))), -5));

                return ['success' => false, 'message' => 'rustup install failed: '.$tail];
            }

            return ['success' => true, 'message' => 'Rust toolchain installed successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Rust install error: '.$e->getMessage()];
        } finally {
            @unlink($installer);
        }
    }

    public function update(): array
    {
        $rustup = $this->cargoHome().DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'rustup';
        if (! is_file($rustup)) {
            return ['success' => false, 'message' => 'Only the app-managed Rust toolchain can be updated here; update a system install with rustup.'];
        }

        try {
            $process = new Process([$rustup, 'update', 'stable'], null, $this->processEnv());
            $process->setTimeout(1200);
            $process->run();

            return $process->isSuccessful()
                ? ['success' => true, 'message' => 'Rust toolchain updated successfully.']
                : ['success' => false, 'message' => 'rustup update failed: '.trim($process->getErrorOutput())];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Rust update error: '.$e->getMessage()];
        }
    }

    public function uninstall(): array
    {
        $dir = $this->installDir();
        if (! is_dir($dir)) {
            return ['success' => true, 'message' => 'Rust toolchain is not installed by the app — nothing to remove.'];
        }

        try {
            $this->deleteDirectory($dir);

            return ['success' => true, 'message' => 'Rust toolchain removed successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to remove Rust toolchain: '.$e->getMessage()];
        }
    }

    public function checkVulnerabilities(): array
    {
        return [];
    }

    private function hostTriple(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        $arch = match (php_uname('m')) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => null,
        };
        if ($arch === null) {
            return null;
        }

        return $arch.(is_file('/etc/alpine-release') ? '-unknown-linux-musl' : '-unknown-linux-gnu');
    }

    /**
     * Download $url to $target and check it against the published .sha256.
     *
     * @return string|null Error message, or null on success.
     */
    private function downloadVerified(string $url, string $target): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 120]]);
        $binary = @file_get_contents($url, false, $ctx);
        $checksum = @file_get_contents($url.'.sha256', false, $ctx);

        if ($binary === false || $checksum === false) {
            return 'Could not download the rustup installer from static.rust-lang.org.';
        }

        $expected = strtolower(strtok(trim($checksum), " \t") ?: '');
        if ($expected === '' || ! hash_equals($expected, hash('sha256', $binary))) {
            return 'The downloaded rustup installer failed its SHA-256 check; aborting.';
        }

        return file_put_contents($target, $binary) === false ? 'Could not write the rustup installer to disk.' : null;
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) && ! is_link($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
