<?php

namespace App\Services\Plugins;

use App\Services\NodeInstallService;
use Symfony\Component\Process\Process;

class Pm2Plugin implements ManagedPlugin
{
    public function __construct(
        private readonly NodeInstallService $nodeInstall,
    ) {}

    public function slug(): string
    {
        return 'pm2';
    }

    public function displayName(): string
    {
        return 'PM2 Process Manager';
    }

    public function description(): string
    {
        return 'Advanced Node.js process manager with built-in load balancer, log management, and zero-downtime reloads.';
    }

    public function category(): string
    {
        return 'process-manager';
    }

    public function installDir(): string
    {
        return storage_path('plugins/pm2');
    }

    public function pm2Script(): string
    {
        return $this->installDir() . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'pm2' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pm2';
    }

    public function isInstalled(): bool
    {
        return is_file($this->pm2Script());
    }

    public function installedVersion(): ?string
    {
        if (! $this->isInstalled()) {
            return null;
        }

        try {
            $process = new Process([$this->nodeInstall->nodeBinary(), $this->pm2Script(), '--version']);
            $process->setTimeout(10);
            $process->setEnv($this->nodeEnv());
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput()) ?: null;
            }
        } catch (\Throwable) {
            // Fall through.
        }

        return null;
    }

    public function fetchLatestVersion(): ?string
    {
        try {
            $ctx  = stream_context_create(['http' => ['timeout' => 10]]);
            $json = @file_get_contents('https://registry.npmjs.org/pm2/latest', false, $ctx);

            if ($json === false) {
                return null;
            }

            $data = json_decode($json, true);

            return isset($data['version']) ? (string) $data['version'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function install(): array
    {
        if (! $this->nodeInstall->isInstalled()) {
            return ['success' => false, 'message' => 'Node.js runtime is required but not installed.'];
        }

        $dir = $this->installDir();
        if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return ['success' => false, 'message' => 'Could not create PM2 install directory: ' . $dir];
        }

        try {
            $process = new Process([
                $this->nodeInstall->npmBinary(),
                'install',
                '--prefix',
                $dir,
                'pm2',
            ]);
            $process->setTimeout(300);
            $process->setEnv($this->nodeEnv());
            $process->run();

            if (! $process->isSuccessful()) {
                return ['success' => false, 'message' => 'npm install failed: ' . trim($process->getErrorOutput())];
            }

            return ['success' => true, 'message' => 'PM2 installed successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'PM2 install error: ' . $e->getMessage()];
        }
    }

    public function update(): array
    {
        if (! $this->nodeInstall->isInstalled()) {
            return ['success' => false, 'message' => 'Node.js runtime is required but not installed.'];
        }

        $dir = $this->installDir();
        if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return ['success' => false, 'message' => 'Could not create PM2 install directory: ' . $dir];
        }

        try {
            $process = new Process([
                $this->nodeInstall->npmBinary(),
                'install',
                '--prefix',
                $dir,
                'pm2@latest',
            ]);
            $process->setTimeout(300);
            $process->setEnv($this->nodeEnv());
            $process->run();

            if (! $process->isSuccessful()) {
                return ['success' => false, 'message' => 'npm install failed: ' . trim($process->getErrorOutput())];
            }

            return ['success' => true, 'message' => 'PM2 updated successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'PM2 update error: ' . $e->getMessage()];
        }
    }

    public function uninstall(): array
    {
        $dir = $this->installDir();
        if (! is_dir($dir)) {
            return ['success' => true, 'message' => 'PM2 is not installed — nothing to remove.'];
        }

        try {
            $this->deleteDirectory($dir);

            return ['success' => true, 'message' => 'PM2 removed successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to remove PM2: ' . $e->getMessage()];
        }
    }

    public function checkVulnerabilities(): array
    {
        if (! $this->isInstalled()) {
            return [];
        }

        if (! $this->nodeInstall->isInstalled()) {
            return [];
        }

        try {
            $process = new Process([
                $this->nodeInstall->npmBinary(),
                'audit',
                '--json',
                '--prefix',
                $this->installDir(),
            ]);
            $process->setTimeout(60);
            $process->setEnv($this->nodeEnv());
            $process->run();

            $output = trim($process->getOutput());
            if ($output === '') {
                return [];
            }

            $data = json_decode($output, true);
            if (! is_array($data)) {
                return [];
            }

            $vulnerabilities = $data['vulnerabilities'] ?? [];
            $found = [];

            foreach ($vulnerabilities as $name => $vuln) {
                $severity = $vuln['severity'] ?? '';
                if (in_array($severity, ['high', 'critical'], true)) {
                    $found[] = sprintf(
                        '%s vulnerability in %s (%s)',
                        ucfirst($severity),
                        $name,
                        $vuln['title'] ?? 'no title'
                    );
                }
            }

            return $found;
        } catch (\Throwable) {
            return [];
        }
    }

    private function nodeEnv(): array
    {
        $nodeBinary = $this->nodeInstall->nodeBinary();
        $nodeBinDir = dirname($nodeBinary);

        return ['PATH' => $nodeBinDir . PATH_SEPARATOR . (getenv('PATH') ?: '')];
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
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
