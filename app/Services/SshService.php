<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SshService
{
    /**
     * @param  array<int, string>  $commands
     */
    public function runCommands(
        string $host,
        int $port,
        string $username,
        ?string $password,
        ?string $rootPath,
        array $commands,
        array &$output,
        ?string $passBinaryOverride = null,
        ?string $keyPathOverride = null
    ): void {
        $commands = array_values(array_filter(array_map('trim', $commands), fn (string $cmd) => $cmd !== ''));
        if ($commands === []) {
            $output[] = 'SSH: no commands to run.';

            return;
        }

        $passBinary = $this->resolvePassBinary($passBinaryOverride);
        $keyPath = $this->resolveKeyPath($keyPathOverride);
        $useAskPass = ($password ?? '') !== '' && $passBinary === '' && $keyPath === '';
        $askPassPath = $useAskPass ? $this->ensureAskPassScript() : null;
        if ($useAskPass && ! $askPassPath) {
            throw new \RuntimeException('SSH password provided but askpass helper could not be created. Provide sshpass or a key path instead.');
        }

        $rootPath = $rootPath ? rtrim(str_replace('\\', '/', $rootPath), '/') : '';
        $env = $this->buildEnv($password, $askPassPath);

        try {
            foreach ($commands as $command) {
                $remoteCommand = $this->wrapRemoteCommand($rootPath, $command);
                $args = $this->buildSshArgs($host, $port, $username, $password, $remoteCommand, $passBinary, $keyPath, $useAskPass);
                $output[] = 'SSH: '.$this->summarizeRemoteCommand($rootPath, $command);
                $process = new Process($args, null, $env);
                $process->setTimeout(600);
                $process->run(function ($type, $buffer) use (&$output) {
                    $output[] = trim($buffer);
                });

                if (! $process->isSuccessful()) {
                    throw new ProcessFailedException($process);
                }
            }
        } finally {
            if ($askPassPath) {
                @unlink($askPassPath);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function runCommand(
        string $host,
        int $port,
        string $username,
        ?string $password,
        ?string $rootPath,
        string $command,
        array &$output,
        ?string $passBinaryOverride = null,
        ?string $keyPathOverride = null
    ): array {
        $command = trim($command);
        if ($command === '') {
            return [];
        }

        $passBinary = $this->resolvePassBinary($passBinaryOverride);
        $keyPath = $this->resolveKeyPath($keyPathOverride);
        $useAskPass = ($password ?? '') !== '' && $passBinary === '' && $keyPath === '';
        $askPassPath = $useAskPass ? $this->ensureAskPassScript() : null;
        if ($useAskPass && ! $askPassPath) {
            throw new \RuntimeException('SSH password provided but askpass helper could not be created. Provide sshpass or a key path instead.');
        }

        $rootPath = $rootPath ? rtrim(str_replace('\\', '/', $rootPath), '/') : '';
        $remoteCommand = $this->wrapRemoteCommand($rootPath, $command);
        $args = $this->buildSshArgs($host, $port, $username, $password, $remoteCommand, $passBinary, $keyPath, $useAskPass);
        $env = $this->buildEnv($password, $askPassPath);

        $output[] = 'SSH: '.$this->summarizeRemoteCommand($rootPath, $command);
        $process = new Process($args, null, $env);
        $process->setTimeout(600);
        $lines = [];
        $process->run(function ($type, $buffer) use (&$output, &$lines) {
            $line = trim($buffer);
            if ($line === '') {
                return;
            }
            $output[] = $line;
            $lines[] = $line;
        });

        if ($askPassPath) {
            @unlink($askPassPath);
        }

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $lines;
    }

    private function wrapRemoteCommand(string $rootPath, string $command): string
    {
        if ($rootPath === '') {
            return $command;
        }

        $escapedRoot = escapeshellarg($rootPath);

        return 'cd '.$escapedRoot.' && '.$command;
    }

    private function summarizeRemoteCommand(string $rootPath, string $command): string
    {
        if ($rootPath === '') {
            return $command;
        }

        return 'cd '.$rootPath.' && '.$command;
    }

    private function sshBinary(): string
    {
        $binary = trim((string) config('gitmanager.ssh.binary', 'ssh'));

        return $binary !== '' ? $binary : 'ssh';
    }

    private function sshPassBinary(): string
    {
        return trim((string) config('gitmanager.ssh.pass_binary', ''));
    }

    private function knownHostsPath(): string
    {
        $path = trim((string) config('gitmanager.ssh.known_hosts', storage_path('app/ssh_known_hosts')));
        $path = $path !== '' ? $path : storage_path('app/ssh_known_hosts');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $path;
    }

    private function strictHostKeyChecking(): string
    {
        $value = trim((string) config('gitmanager.ssh.strict_host_key_checking', 'accept-new'));

        return $value !== '' ? $value : 'accept-new';
    }

    private function sshKeyPath(): string
    {
        return trim((string) config('gitmanager.ssh.key_path', ''));
    }

    /**
     * @return array<int, string>
     */
    private function buildSshArgs(
        string $host,
        int $port,
        string $username,
        ?string $password,
        string $remoteCommand,
        ?string $passBinaryOverride = null,
        ?string $keyPathOverride = null,
        bool $useAskPass = false
    ): array {
        $sshBinary = $this->sshBinary();
        $passBinary = $this->resolvePassBinary($passBinaryOverride);
        $knownHosts = $this->knownHostsPath();
        $strict = $this->strictHostKeyChecking();
        $keyPath = $this->resolveKeyPath($keyPathOverride);

        $baseArgs = [
            $sshBinary,
            '-p',
            (string) $port,
            '-o',
            'UserKnownHostsFile='.$knownHosts,
            '-o',
            'StrictHostKeyChecking='.$strict,
        ];

        if ($useAskPass) {
            $baseArgs[] = '-o';
            $baseArgs[] = 'BatchMode=no';
            $baseArgs[] = '-o';
            $baseArgs[] = 'NumberOfPasswordPrompts=1';
        } elseif ($passBinary === '' || $password === null || $password === '') {
            $baseArgs[] = '-o';
            $baseArgs[] = 'BatchMode=yes';
        }

        if ($password !== null && $password !== '' && $keyPath === '') {
            $baseArgs[] = '-o';
            $baseArgs[] = 'PreferredAuthentications=password';
            $baseArgs[] = '-o';
            $baseArgs[] = 'PasswordAuthentication=yes';
            $baseArgs[] = '-o';
            $baseArgs[] = 'PubkeyAuthentication=no';
        }

        if ($keyPath !== '') {
            $baseArgs[] = '-i';
            $baseArgs[] = $keyPath;
        }

        $destination = $username.'@'.$host;

        $args = array_merge($baseArgs, [$destination, $remoteCommand]);

        if ($passBinary !== '' && $password !== null && $password !== '') {
            $args = array_merge([$passBinary, '-p', $password], $args);
        }

        return $args;
    }

    private function resolvePassBinary(?string $override): string
    {
        $override = trim((string) $override);
        if ($override !== '') {
            return $override;
        }

        return $this->sshPassBinary();
    }

    private function resolveKeyPath(?string $override): string
    {
        $override = trim((string) $override);
        if ($override !== '') {
            return $override;
        }

        return $this->sshKeyPath();
    }

    private function buildEnv(?string $password, ?string $askPassPath): array
    {
        $env = $this->baseEnv();

        if ($askPassPath) {
            $env['SSH_ASKPASS'] = $askPassPath;
            $env['SSH_ASKPASS_REQUIRE'] = 'force';
            if (! isset($env['DISPLAY']) || trim((string) $env['DISPLAY']) === '') {
                $env['DISPLAY'] = '1';
            }
            $env['SSH_PASSWORD'] = $password ?? '';
        }

        return $env;
    }

    private function baseEnv(): array
    {
        $env = getenv();

        return is_array($env) ? $env : [];
    }

    private function ensureAskPassScript(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = $this->writeAskPassScript(sys_get_temp_dir(), 'bat');

            return $path !== '' ? $path : null;
        }

        $path = $this->writeAskPassScript(sys_get_temp_dir(), 'sh');

        return $path !== '' ? $path : null;
    }

    private function writeAskPassScript(string $directory, string $extension): string
    {
        $directory = trim($directory);
        if ($directory === '') {
            return '';
        }

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $filename = 'ssh-askpass-'.uniqid('', true).'.'.$extension;
        $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

        if ($extension === 'bat') {
            file_put_contents($path, "@echo off\r\n"
                ."echo %SSH_PASSWORD%\r\n");

            return $path;
        }

        file_put_contents($path, "#!/bin/sh\n"
            .'echo "$SSH_PASSWORD"'."\n");
        @chmod($path, 0700);

        return $path;
    }
}
