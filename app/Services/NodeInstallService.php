<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class NodeInstallService
{
    // Fallback LTS version for first-time installation when no upstream version is requested.
    private const NODE_LTS_VERSION = '20.19.2';

    // Stored inside the app's own storage to avoid requiring root/sudo
    private const INSTALL_DIR_NAME = 'node-runtime';

    public function detect(): array
    {
        // Try the bundled binary first, then fall back to whatever is on PATH
        foreach ($this->candidateBinaries() as $binary) {
            $result = $this->probe($binary);
            if ($result['found']) {
                return $result;
            }
        }

        return [
            'found' => false,
            'binary' => null,
            'version' => null,
            'source' => null,
        ];
    }

    public function isInstalled(): bool
    {
        return $this->detect()['found'];
    }

    public function nodeBinary(): string
    {
        $bundled = $this->bundledBinary();
        if (is_file($bundled) && is_executable($bundled)) {
            return $bundled;
        }

        foreach (['node', 'nodejs'] as $candidate) {
            $result = $this->probe($candidate);
            if ($result['found']) {
                return $candidate;
            }
        }

        return 'node';
    }

    public function npmBinary(): string
    {
        $dir = $this->bundledBinDir();
        $bundled = $dir.DIRECTORY_SEPARATOR.($this->isWindows() ? 'npm.cmd' : 'npm');
        if (is_file($bundled)) {
            return $bundled;
        }

        return 'npm';
    }

    /**
     * Download and extract the Node.js LTS binary into storage/node-runtime.
     * Returns ['success' => bool, 'message' => string].
     */
    public function install(?string $version = null): array
    {
        $version ??= self::NODE_LTS_VERSION;
        $version = ltrim($version, 'v');

        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return ['success' => false, 'message' => __('Invalid Node.js version requested.')];
        }

        if ($this->isWindows()) {
            return $this->installWindows($version);
        }

        return $this->installUnix($version);
    }

    public function uninstall(): array
    {
        $dir = $this->installDir();
        if (! is_dir($dir)) {
            return ['success' => true, 'message' => 'Bundled Node.js runtime not found — nothing to remove.'];
        }

        try {
            $this->deleteDirectory($dir);

            return ['success' => true, 'message' => 'Bundled Node.js runtime removed.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to remove runtime: '.$e->getMessage()];
        }
    }

    public function installDir(): string
    {
        return storage_path(self::INSTALL_DIR_NAME);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function candidateBinaries(): array
    {
        return array_filter([
            $this->bundledBinary(),
            'node',
            'nodejs',
        ]);
    }

    private function bundledBinDir(): string
    {
        // Node tarballs extract as node-v{version}-{os}-{arch}/bin/
        $dir = $this->installDir();
        if (! is_dir($dir)) {
            return $dir;
        }

        // On Windows the exe lives directly in the extracted folder
        if ($this->isWindows()) {
            return $dir;
        }

        // On Unix the binary lives in the bin/ subdirectory inside the extracted tarball folder.
        $entries = glob($dir.DIRECTORY_SEPARATOR.'node-v*') ?: [];

        return isset($entries[0])
            ? $entries[0].DIRECTORY_SEPARATOR.'bin'
            : $dir.DIRECTORY_SEPARATOR.'bin';
    }

    private function bundledBinary(): string
    {
        $bin = $this->bundledBinDir();

        return $this->isWindows()
            ? $bin.DIRECTORY_SEPARATOR.'node.exe'
            : $bin.DIRECTORY_SEPARATOR.'node';
    }

    private function probe(string $binary): array
    {
        if ($binary === '' || (str_contains($binary, DIRECTORY_SEPARATOR) && ! is_file($binary))) {
            return ['found' => false, 'binary' => $binary, 'version' => null, 'source' => null];
        }

        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(5);
            $process->run();
        } catch (\Throwable) {
            return ['found' => false, 'binary' => $binary, 'version' => null, 'source' => null];
        }

        if (! $process->isSuccessful()) {
            return ['found' => false, 'binary' => $binary, 'version' => null, 'source' => null];
        }

        $version = trim($process->getOutput());
        $source = str_contains($binary, $this->installDir()) ? 'bundled' : 'system';

        return [
            'found' => true,
            'binary' => $binary,
            'version' => $version,
            'source' => $source,
        ];
    }

    private function installUnix(string $version): array
    {
        $os = $this->unixOs();
        if ($os === null) {
            return ['success' => false, 'message' => 'Unsupported operating system for automatic Node.js install.'];
        }

        $arch = $this->unixArch();
        $filename = "node-v{$version}-{$os}-{$arch}.tar.gz";
        $url = "https://nodejs.org/dist/v{$version}/{$filename}";
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename;
        $staging = $this->stagingDir();

        // Download
        $download = $this->download($url, $tmp);
        if (! $download['success']) {
            return $download;
        }

        if (! @mkdir($staging, 0755, true) && ! is_dir($staging)) {
            return ['success' => false, 'message' => __('Could not create staging directory: :path', ['path' => $staging])];
        }

        // Extract into staging so an unsuccessful update cannot remove the current runtime.
        try {
            $process = new Process(['tar', '-xzf', $tmp, '-C', $staging]);
            $process->setTimeout(120);
            $process->run();
        } catch (\Throwable $e) {
            @unlink($tmp);
            $this->deleteDirectory($staging);

            return ['success' => false, 'message' => 'Extraction failed: '.$e->getMessage()];
        } finally {
            @unlink($tmp);
        }

        if (! $process->isSuccessful()) {
            $this->deleteDirectory($staging);

            return ['success' => false, 'message' => 'tar extraction failed: '.trim($process->getErrorOutput())];
        }

        if (! is_file($staging.DIRECTORY_SEPARATOR."node-v{$version}-{$os}-{$arch}".DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'node')) {
            $this->deleteDirectory($staging);

            return ['success' => false, 'message' => __('Node.js archive did not contain the expected binary.')];
        }

        $replace = $this->replaceInstallDirectory($staging);
        if (! $replace['success']) {
            return $replace;
        }

        $detected = $this->detect();
        if (! $detected['found']) {
            return ['success' => false, 'message' => 'Node.js installed but binary not detected after extraction.'];
        }

        return ['success' => true, 'message' => "Node.js {$detected['version']} installed successfully (bundled)."];
    }

    private function installWindows(string $version): array
    {
        $arch = PHP_INT_SIZE === 8 ? 'x64' : 'x86';
        $filename = "node-v{$version}-win-{$arch}.zip";
        $url = "https://nodejs.org/dist/v{$version}/{$filename}";
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename;
        $staging = $this->stagingDir();

        $download = $this->download($url, $tmp);
        if (! $download['success']) {
            return $download;
        }

        if (! @mkdir($staging, 0755, true) && ! is_dir($staging)) {
            return ['success' => false, 'message' => __('Could not create staging directory: :path', ['path' => $staging])];
        }

        try {
            $process = new Process(['powershell', '-Command',
                "Expand-Archive -Path '{$tmp}' -DestinationPath '{$staging}' -Force",
            ]);
            $process->setTimeout(120);
            $process->run();
        } catch (\Throwable $e) {
            @unlink($tmp);
            $this->deleteDirectory($staging);

            return ['success' => false, 'message' => 'Extraction failed: '.$e->getMessage()];
        } finally {
            @unlink($tmp);
        }

        if (! $process->isSuccessful()) {
            $this->deleteDirectory($staging);

            return ['success' => false, 'message' => 'Zip extraction failed: '.trim($process->getErrorOutput())];
        }

        // Move contents of extracted folder up one level so node.exe sits directly in the runtime directory.
        $extracted = glob($staging.DIRECTORY_SEPARATOR.'node-v*') ?: [];
        if ($extracted !== []) {
            $inner = $extracted[0];
            foreach (scandir($inner) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                @rename($inner.DIRECTORY_SEPARATOR.$entry, $staging.DIRECTORY_SEPARATOR.$entry);
            }
            @rmdir($inner);
        }

        if (! is_file($staging.DIRECTORY_SEPARATOR.'node.exe')) {
            $this->deleteDirectory($staging);

            return ['success' => false, 'message' => __('Node.js archive did not contain the expected binary.')];
        }

        $replace = $this->replaceInstallDirectory($staging);
        if (! $replace['success']) {
            return $replace;
        }

        $detected = $this->detect();
        if (! $detected['found']) {
            return ['success' => false, 'message' => 'Node.js installed but binary not detected after extraction.'];
        }

        return ['success' => true, 'message' => "Node.js {$detected['version']} installed successfully (bundled)."];
    }

    private function download(string $url, string $dest): array
    {
        // Try curl, then file_get_contents as fallback
        try {
            $process = new Process(['curl', '-fsSL', '-o', $dest, $url]);
            $process->setTimeout(300);
            $process->run();

            if ($process->isSuccessful() && is_file($dest) && filesize($dest) > 0) {
                return ['success' => true];
            }
        } catch (\Throwable) {
            // fall through to PHP fallback
        }

        // Fallback: PHP stream
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 300]]);
            $data = @file_get_contents($url, false, $ctx);
            if ($data !== false && strlen($data) > 0) {
                file_put_contents($dest, $data);

                return ['success' => true];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Download failed: '.$e->getMessage()];
        }

        return ['success' => false, 'message' => "Failed to download Node.js from {$url}"];
    }

    private function unixOs(): ?string
    {
        $os = php_uname('s');
        if (stripos($os, 'linux') !== false) {
            return 'linux';
        }
        if (stripos($os, 'darwin') !== false) {
            return 'darwin';
        }

        return null;
    }

    private function unixArch(): string
    {
        $machine = php_uname('m');
        if (str_contains($machine, 'arm') || str_contains($machine, 'aarch')) {
            return PHP_INT_SIZE === 8 ? 'arm64' : 'armv7l';
        }

        return PHP_INT_SIZE === 8 ? 'x64' : 'x86';
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    private function stagingDir(): string
    {
        return $this->installDir().'.staging.'.bin2hex(random_bytes(8));
    }

    /** @return array{success: bool, message?: string} */
    private function replaceInstallDirectory(string $staging): array
    {
        $dir = $this->installDir();
        $previous = $dir.'.previous.'.bin2hex(random_bytes(8));

        if (is_dir($dir) && ! @rename($dir, $previous)) {
            $this->deleteDirectory($staging);

            return ['success' => false, 'message' => __('Could not replace the existing Node.js runtime. Stop Node.js processes and try again.')];
        }

        if (@rename($staging, $dir)) {
            if (is_dir($previous)) {
                $this->deleteDirectory($previous);
            }

            return ['success' => true];
        }

        if (is_dir($previous)) {
            @rename($previous, $dir);
        }
        $this->deleteDirectory($staging);

        return ['success' => false, 'message' => __('Could not activate the updated Node.js runtime.')];
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
