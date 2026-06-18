<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class RuntimeDiagnosticsService
{
    private const VERSION_FLAG = '--version';

    /** @return array<string, array{found: bool, version: ?string, path: ?string, label: string, guidance: ?string, installAction: ?string, note?: string, error: ?string}> */
    public function detect(): array
    {
        return [
            'php'      => $this->probe('php', self::VERSION_FLAG, 'PHP'),
            'composer' => $this->probe('composer', self::VERSION_FLAG, 'Composer'),
            'npm'      => $this->probeNpm(),
            'python'   => $this->probeFirstOf(['python3', 'python'], self::VERSION_FLAG, 'Python'),
            'pip'      => $this->probePip(),
        ];
    }

    public function recheck(): array
    {
        return $this->detect();
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

    /**
     * Probe npm. On Windows, npm may crash during subprocess initialization due to
     * CryptAPI restrictions when running under a web-server process account. If the
     * binary invocation fails, fall back to reading the version from npm's own
     * package.json, which requires no subprocess crypto.
     */
    private function probeNpm(): array
    {
        $note = 'Checks the host PATH only. App-managed Node.js is configured under Runtime Diagnostics.';

        $result = $this->probe('npm', '-v', 'System npm (PATH)', $note);
        if ($result['found'] && $result['error'] === null) {
            return $result;
        }

        $version = $this->npmVersionFromPackageJson();
        if ($version !== null) {
            return $this->detected('npm', 'System npm (PATH)', $note, $version);
        }

        return $result;
    }

    private function npmVersionFromPackageJson(): ?string
    {
        try {
            // Locate the npm executable on PATH.
            $locator = PHP_OS_FAMILY === 'Windows' ? ['where', 'npm'] : ['which', 'npm'];
            $where = new Process($locator, null, $this->buildEnv());
            $where->setTimeout(5);
            $where->run();

            if (! $where->isSuccessful()) {
                return null;
            }

            $npmBin = trim(explode("\n", $where->getOutput())[0]);

            // Resolve the npm package.json relative to where the binary lives.
            // Standard Node.js installs place npm at:
            //   Windows: <nodejs_dir>\npm.cmd  → <nodejs_dir>\node_modules\npm\package.json
            //   POSIX:   <prefix>/bin/npm      → <prefix>/lib/node_modules/npm/package.json
            $dir = dirname(realpath($npmBin) ?: $npmBin);
            $candidates = [
                $dir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'npm' . DIRECTORY_SEPARATOR . 'package.json',
                dirname($dir) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'npm' . DIRECTORY_SEPARATOR . 'package.json',
            ];

            foreach ($candidates as $path) {
                if (is_file($path)) {
                    $data = json_decode(file_get_contents($path), true);

                    return $data['version'] ?? null;
                }
            }
        } catch (\Throwable) {
            // Fall through.
        }

        return null;
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
