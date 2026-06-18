<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class RuntimeDiagnosticsService
{
    private const VERSION_FLAG = '--version';
    private const CACHE_KEY = 'runtime_diagnostics';
    private const CACHE_TTL = 120;

    /** @return array<string, array{found: bool, version: ?string, path: ?string, label: string, guidance: ?string, installAction: ?string, note?: string, error: ?string}> */
    public function detect(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->run());
    }

    public function recheck(): array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->detect();
    }

    private function run(): array
    {
        return [
            'php'      => $this->probe('php', self::VERSION_FLAG, 'PHP'),
            'composer' => $this->probe('composer', self::VERSION_FLAG, 'Composer'),
            'python'   => $this->probeFirstOf(['python3', 'python'], self::VERSION_FLAG, 'Python'),
            'pip'      => $this->probePip(),
        ];
    }

    /**
     * Build a process environment that includes the real host PATH.
     * Symfony Process inherits $_ENV, which is often sparse under web servers.
     * We use getenv() to capture the shell PATH, then prepend PHP's own bin
     * directory so tools like Composer can call php.exe.
     *
     * @return array<string, string>
     */
    private function buildEnv(): array
    {
        $path = getenv('PATH') ?: (getenv('Path') ?: '');
        $phpDir = dirname(PHP_BINARY);

        if ($phpDir !== '' && !str_contains($path, $phpDir)) {
            $path = $phpDir . PATH_SEPARATOR . $path;
        }

        // Windows uses 'Path'; POSIX uses 'PATH'.
        $key = PHP_OS_FAMILY === 'Windows' ? 'Path' : 'PATH';

        return [$key => $path];
    }

    private function probe(string $binary, string $versionFlag, string $label, ?string $note = null): array
    {
        try {
            $process = new Process([$binary, $versionFlag], null, $this->buildEnv());
            $process->setTimeout(10);
            $process->run();
            $output = trim($process->getOutput() ?: $process->getErrorOutput());

            if ($process->isSuccessful()) {
                return $this->detected($binary, $label, $note, $this->extractVersion($output));
            }

            if ($output !== '') {
                // Binary exists but exited non-zero — show as broken, not detected.
                return $this->broken($binary, $label, $note, $output);
            }
        } catch (\Throwable) {
            // Binary not found or process could not start — fall through to missing.
        }

        return $this->missing($binary, $label, $note);
    }

    /**
     * Try pip/pip3 first; if broken or absent, fall back to `python -m pip`
     * because pip may be installed for a Python version that differs from the
     * one the standalone pip shim resolves to.
     */
    private function probePip(): array
    {
        foreach (['pip3', 'pip'] as $binary) {
            $result = $this->probe($binary, self::VERSION_FLAG, 'pip');
            if ($result['found'] && $result['error'] === null) {
                return $result;
            }
        }

        foreach (['python3', 'python'] as $python) {
            try {
                $process = new Process([$python, '-m', 'pip', self::VERSION_FLAG], null, $this->buildEnv());
                $process->setTimeout(10);
                $process->run();
                $output = trim($process->getOutput() ?: $process->getErrorOutput());

                if ($process->isSuccessful()) {
                    return $this->detected("{$python} -m pip", 'pip', null, $this->extractVersion($output));
                }
            } catch (\Throwable) {
                // Try next candidate.
            }
        }

        return $this->missing('pip', 'pip', null);
    }

    private function probeFirstOf(array $candidates, string $versionFlag, string $label): array
    {
        $firstBroken = null;

        foreach ($candidates as $binary) {
            $result = $this->probe($binary, $versionFlag, $label);

            if ($result['found'] && $result['error'] === null) {
                return $result;
            }

            if ($result['found'] && $firstBroken === null) {
                $firstBroken = $result;
            }
        }

        return $firstBroken ?? $this->missing($candidates[0], $label, null);
    }

    private function detected(string $path, string $label, ?string $note, string $version): array
    {
        return ['found' => true, 'version' => $version, 'path' => $path, 'label' => $label, 'guidance' => null, 'installAction' => null, 'note' => $note, 'error' => null];
    }

    private function broken(string $path, string $label, ?string $note, string $error): array
    {
        return ['found' => true, 'version' => null, 'path' => $path, 'label' => $label, 'guidance' => null, 'installAction' => null, 'note' => $note, 'error' => $error];
    }

    private function missing(string $binary, string $label, ?string $note): array
    {
        return ['found' => false, 'version' => null, 'path' => null, 'label' => $label, 'guidance' => $this->installGuidance($binary), 'installAction' => $this->installAction($binary), 'note' => $note, 'error' => null];
    }

    private function extractVersion(string $output): string
    {
        if (preg_match('/\d+\.\d+[\.\d]*/', $output, $m)) {
            return $m[0];
        }

        return $output !== '' ? explode("\n", $output)[0] : 'unknown';
    }

    private function installAction(string $binary): ?string
    {
        return match ($binary) {
            default => null,
        };
    }

    private function installGuidance(string $binary): string
    {
        $os = PHP_OS_FAMILY;

        return match ($binary) {
            'composer' => $os === 'Windows'
                ? 'winget install Composer.Composer'
                : 'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer',
            'npm'      => $os === 'Windows'
                ? 'winget install OpenJS.NodeJS.LTS'
                : 'apt install nodejs npm',
            'python3', 'python' => $os === 'Windows'
                ? 'winget install Python.Python.3'
                : 'apt install python3',
            'pip3', 'pip' => $os === 'Windows'
                ? 'python -m ensurepip --upgrade'
                : 'apt install python3-pip',
            default => "Install {$binary} via your system package manager",
        };
    }
}
