<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class RuntimeDiagnosticsService
{
    /** @return array<string, array{found: bool, version: ?string, path: ?string, label: string, guidance: ?string}> */
    public function detect(): array
    {
        return [
            'php'      => $this->probe('php', '--version', 'PHP'),
            'composer' => $this->probe('composer', '--version', 'Composer'),
            'npm'      => $this->probe('npm', '--version', 'npm'),
            'python'   => $this->probeFirstOf(['python3', 'python'], '--version', 'Python'),
            'pip'      => $this->probeFirstOf(['pip3', 'pip'], '--version', 'pip'),
        ];
    }

    public function recheck(): array
    {
        return $this->detect();
    }

    private function probe(string $binary, string $versionFlag, string $label): array
    {
        try {
            $process = new Process([$binary, $versionFlag]);
            $process->setTimeout(10);
            $process->run();
            $output = trim($process->getOutput() ?: $process->getErrorOutput());
            if ($process->isSuccessful() || $output !== '') {
                $version = $this->extractVersion($output);

                return ['found' => true, 'version' => $version, 'path' => $binary, 'label' => $label, 'guidance' => null];
            }
        } catch (\Throwable) {
        }

        return ['found' => false, 'version' => null, 'path' => null, 'label' => $label, 'guidance' => $this->installGuidance($binary)];
    }

    private function probeFirstOf(array $candidates, string $versionFlag, string $label): array
    {
        foreach ($candidates as $binary) {
            $result = $this->probe($binary, $versionFlag, $label);
            if ($result['found']) {
                return $result;
            }
        }

        return ['found' => false, 'version' => null, 'path' => null, 'label' => $label, 'guidance' => $this->installGuidance($candidates[0])];
    }

    private function extractVersion(string $output): string
    {
        if (preg_match('/\d+\.\d+[\.\d]*/', $output, $m)) {
            return $m[0];
        }

        return $output !== '' ? explode("\n", $output)[0] : 'unknown';
    }

    private function installGuidance(string $binary): string
    {
        $os = PHP_OS_FAMILY;

        return match ($binary) {
            'composer' => $os === 'Windows'
                ? 'Download from https://getcomposer.org/download/'
                : 'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer',
            'npm'      => $os === 'Windows'
                ? 'Install Node.js from https://nodejs.org (npm is included)'
                : 'apt install nodejs npm   # or use the GWM Node.js installer above',
            'python3', 'python' => $os === 'Windows'
                ? 'Download from https://python.org/downloads/'
                : 'apt install python3',
            'pip3', 'pip' => $os === 'Windows'
                ? 'python -m ensurepip --upgrade'
                : 'apt install python3-pip',
            default => "Install {$binary} via your system package manager",
        };
    }
}
