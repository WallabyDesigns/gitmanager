<?php

namespace App\Services\Concerns;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

trait RunsProcesses
{
    private function runProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeCommand($command);
        $processId = $this->logProcessStart($command, $workingDir, $output);
        $process = new Process($command, $workingDir, array_merge($this->baseEnv(), $this->gitEnv()));
        $this->applyProcessTimeout($process);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function runProjectProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeCommand($command);
        $processId = $this->logProcessStart($command, $workingDir, $output);
        $process = new Process($command, $workingDir, $this->projectEnvForPath($workingDir));
        $this->applyProcessTimeout($process);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function runProjectProcessWithEnv(array $command, array &$output = [], ?string $workingDir = null, array $extraEnv = [], bool $throwOnFailure = true): Process
    {
        $command = $this->normalizeCommand($command);
        $env = array_merge($this->projectEnvForPath($workingDir), $extraEnv);
        $processId = $this->logProcessStart($command, $workingDir, $output);
        $process = new Process($command, $workingDir, $env);
        $this->applyProcessTimeout($process);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function runProjectShellCommand(string $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $trimmed = ltrim($command);
        if (str_starts_with($trimmed, 'php ')) {
            $command = $this->phpBinary().' '.substr($trimmed, 4);
        }

        $processId = $this->logProcessStartShell($command, $workingDir, $output);
        $process = Process::fromShellCommandline($command, $workingDir, $this->projectEnvForPath($workingDir));
        $this->applyProcessTimeout($process);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function runShellCommand(string $command, array &$output = [], ?string $workingDir = null): Process
    {
        $trimmed = ltrim($command);
        if (str_starts_with($trimmed, 'php ')) {
            $command = $this->phpBinary().' '.substr($trimmed, 4);
        }

        $processId = $this->logProcessStartShell($command, $workingDir, $output);
        $process = Process::fromShellCommandline($command, $workingDir, array_merge($this->baseEnv(), $this->gitEnv()));
        $this->applyProcessTimeout($process);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
            $this->maybeStreamOutput($output);
        });
        $this->logProcessEnd($processId, $process, $output);

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function normalizeCommand(array $command): array
    {
        $binary = $command[0] ?? '';
        if ($binary === 'composer') {
            $composerPath = $this->resolveComposerBinaryPath();
            $phpBinary = $this->phpBinary();
            if ($composerPath !== '' && $phpBinary !== '') {
                array_shift($command);
                array_unshift($command, $composerPath);
                array_unshift($command, $phpBinary);

                return $command;
            }
        }

        $command[0] = match ($binary) {
            'git' => $this->gitBinary(),
            'composer' => $this->composerBinary(),
            'npm' => $this->npmBinary(),
            'php' => $this->phpBinary(),
            default => $binary,
        };

        return $command;
    }

    private function applyProcessTimeout(Process $process): void
    {
        $timeout = (int) config('gitmanager.process_timeout', 600);
        if ($timeout <= 0) {
            return;
        }

        $process->setTimeout($timeout);

        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 30);
        }
    }

    private function runWithSingleRetry(callable $callback, array &$output, string $label, ?callable $shouldRetry = null): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            if ($shouldRetry && ! $shouldRetry($exception)) {
                throw $exception;
            }
            $output[] = $label.' failed. Retrying once.';
            $callback();
        }
    }

    private function logProcessStart(array $command, ?string $workingDir, array &$output): int
    {
        $this->processCounter++;
        $line = 'Process #'.$this->processCounter.' started: '.$this->formatCommand($command);
        if ($workingDir) {
            $line .= ' (path: '.$workingDir.')';
        }
        $output[] = $line;
        $this->maybeStreamOutput($output, true);

        return $this->processCounter;
    }

    private function logProcessStartShell(string $command, ?string $workingDir, array &$output): int
    {
        $this->processCounter++;
        $line = 'Process #'.$this->processCounter.' started: '.$command;
        if ($workingDir) {
            $line .= ' (path: '.$workingDir.')';
        }
        $output[] = $line;
        $this->maybeStreamOutput($output, true);

        return $this->processCounter;
    }

    private function logProcessEnd(int $processId, Process $process, array &$output): void
    {
        $code = $process->getExitCode();
        $output[] = 'Process #'.$processId.' finished with exit code '.($code ?? 'null').'.';
        $this->maybeStreamOutput($output, true);
    }

    private function formatCommand(array $command): string
    {
        return implode(' ', array_map(static function ($part) {
            $part = (string) $part;

            return str_contains($part, ' ') ? '"'.$part.'"' : $part;
        }, $command));
    }

    private function gitBinary(): string
    {
        $configured = trim((string) config('gitmanager.git_binary', 'git'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'git';
    }

    private function composerBinary(): string
    {
        $configured = trim((string) config('gitmanager.composer_binary', 'composer'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'composer';
    }

    private function resolveComposerBinaryPath(): string
    {
        $configured = trim($this->composerBinary());
        $configured = trim($configured, "\"' ");
        if ($configured === '') {
            return '';
        }

        if ($this->isAbsolutePath($configured) && file_exists($configured)) {
            return $configured;
        }

        if (str_contains($configured, DIRECTORY_SEPARATOR) && file_exists($configured)) {
            return $configured;
        }

        $resolved = $this->resolveBinaryFromPath($configured);

        return $resolved ?: '';
    }

    private function resolveBinaryFromPath(string $binary): ?string
    {
        $binary = trim($binary);
        if ($binary === '') {
            return null;
        }

        if ($this->isAbsolutePath($binary)) {
            return $binary;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) && file_exists($binary)) {
            return $binary;
        }

        $path = getenv('PATH') ?: ($_SERVER['PATH'] ?? '');
        if ($path === '') {
            return null;
        }

        $extensions = [''];
        if (PHP_OS_FAMILY === 'Windows') {
            $extensions = ['', '.exe', '.bat', '.cmd'];
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }

            foreach ($extensions as $ext) {
                $candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary.$ext;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function npmBinary(): string
    {
        $configured = trim((string) config('gitmanager.npm_binary', 'npm'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'npm';
    }

    private function phpBinary(): string
    {
        $override = $this->resolveEnvOverride([
            'GWM_PHP_PATH',
            'GWM_PHP_BINARY',
            'GPM_PHP_PATH',
            'GPM_PHP_BINARY',
        ]);
        if ($override !== null) {
            return $override;
        }

        $configured = trim((string) config('gitmanager.php_binary', 'php'));
        $configured = trim($configured, "\"' ");

        return $configured !== '' ? $configured : 'php';
    }

    private function resolveEnvOverride(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->readEnvOverride($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function readEnvOverride(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_SERVER[$key] ?? ($_ENV[$key] ?? '');
        }

        $value = is_string($value) ? trim($value) : '';
        if ($value !== '') {
            return $value;
        }

        $fromEnvFile = $this->getLaravelEnvValue(base_path(), $key);
        $fromEnvFile = $fromEnvFile !== null ? trim($fromEnvFile) : '';

        return $fromEnvFile !== '' ? $fromEnvFile : null;
    }

    private function gitEnv(): array
    {
        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
        ];

        $token = trim((string) config('services.github.token', env('GITHUB_TOKEN')));
        if ($token === '') {
            return $env;
        }

        $askPass = $this->ensureAskPassScript();
        if ($askPass) {
            $env['GIT_ASKPASS'] = $askPass;
            $env['GIT_USERNAME'] = 'x-access-token';
            $env['GIT_PASSWORD'] = $token;
        }

        return $env;
    }

    private function baseEnv(): array
    {
        $env = getenv();
        $env = is_array($env) ? $env : [];

        $extraPath = trim((string) config('gitmanager.process_path', ''));
        $extraPath = trim($extraPath, "\"' ");
        if ($extraPath !== '') {
            $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
            $current = $env[$pathKey] ?? '';
            $env[$pathKey] = $extraPath.PATH_SEPARATOR.$current;
        }

        $phpBinary = $this->phpBinary();
        if (str_contains($phpBinary, DIRECTORY_SEPARATOR) || str_contains($phpBinary, '/')) {
            $phpDir = dirname($phpBinary);
            if ($phpDir !== '' && $phpDir !== '.') {
                $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
                $current = $env[$pathKey] ?? '';
                if (! str_contains($current, $phpDir)) {
                    $env[$pathKey] = $phpDir.PATH_SEPARATOR.$current;
                }
            }
        }

        return $env;
    }

    private function projectEnv(): array
    {
        $env = $this->baseEnv();
        $blockedPrefixes = [
            'APP_', 'DB_', 'CACHE_', 'SESSION_', 'QUEUE_', 'REDIS_',
            'MAIL_', 'FILESYSTEM_', 'LOG_', 'BROADCAST_', 'MEMCACHED_',
        ];

        foreach (array_keys($env) as $key) {
            $upperKey = strtoupper((string) $key);
            foreach ($blockedPrefixes as $prefix) {
                if (str_starts_with($upperKey, $prefix)) {
                    unset($env[$key]);
                    break;
                }
            }
        }

        return $env;
    }

    private function projectEnvForPath(?string $workingDir): array
    {
        $env = $this->projectEnv();
        if (! $workingDir) {
            return $env;
        }

        $envRoot = $this->findEnvRoot($workingDir);
        if (! $envRoot) {
            return $env;
        }

        $overrides = $this->readEnvFile($envRoot.DIRECTORY_SEPARATOR.'.env');
        if ($overrides === []) {
            return $env;
        }

        $allowedPrefixes = [
            'APP_', 'DB_', 'CACHE_', 'SESSION_', 'QUEUE_', 'REDIS_',
            'MAIL_', 'FILESYSTEM_', 'LOG_', 'BROADCAST_', 'MEMCACHED_',
        ];

        foreach ($overrides as $key => $value) {
            $upperKey = strtoupper((string) $key);
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($upperKey, $prefix)) {
                    $env[$key] = $value;
                    break;
                }
            }
        }

        return $env;
    }

    private function findEnvRoot(string $path): ?string
    {
        return $this->walkUpForMarker($path, '.env');
    }

    /**
     * @return array<string, string>
     */
    private function readEnvFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
                continue;
            }

            $key = $matches[1];
            $value = trim($matches[2]);

            if ($value === '') {
                $values[$key] = '';
                continue;
            }

            $firstChar = $value[0];
            $lastChar = substr($value, -1);
            if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                $value = substr($value, 1, -1);
                if ($firstChar === '"') {
                    $value = str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], $value);
                }
            } else {
                if (str_contains($value, ' #')) {
                    $value = explode(' #', $value, 2)[0];
                } elseif (str_contains($value, "\t#")) {
                    $value = explode("\t#", $value, 2)[0];
                }
            }

            $values[$key] = trim($value);
        }

        return $values;
    }

    private function ensureAskPassScript(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            foreach ($this->askPassDirectories() as $directory) {
                $path = $this->writeAskPassScript($directory, 'bat');
                if ($path !== '') {
                    return $path;
                }
            }

            return null;
        }

        foreach ($this->askPassDirectories() as $directory) {
            $path = $this->writeAskPassScript($directory, 'sh', $directory === sys_get_temp_dir());
            if ($this->isAskPassExecutable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function writeAskPassScript(string $directory, string $extension, bool $unique = false): string
    {
        $directory = trim($directory);
        if ($directory === '') {
            return '';
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $filename = $unique ? 'git-askpass-'.uniqid('', true).'.'.$extension : 'git-askpass.'.$extension;
        $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

        if ($extension === 'bat') {
            if (! file_exists($path)) {
                file_put_contents($path, "@echo off\r\n"
                    ."echo %* | findstr /I \"Username\" >nul\r\n"
                    ."if %errorlevel%==0 (\r\n"
                    ."  echo %GIT_USERNAME%\r\n"
                    .") else (\r\n"
                    ."  echo %GIT_PASSWORD%\r\n"
                    .")\r\n");
            }

            return $path;
        }

        if (! file_exists($path)) {
            file_put_contents($path, "#!/bin/sh\n"
                ."case \"$1\" in\n"
                .'  *Username*) echo "${GIT_USERNAME:-}";;'."\n"
                .'  *) echo "${GIT_PASSWORD:-}";;'."\n"
                ."esac\n");
        }

        @chmod($path, 0700);

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function askPassDirectories(): array
    {
        $paths = [];
        $configured = trim((string) config('gitmanager.askpass_dir', ''));
        if ($configured !== '') {
            $paths[] = $configured;
        }

        $paths[] = storage_path('app');
        $paths[] = sys_get_temp_dir();

        return array_values(array_unique(array_filter($paths)));
    }

    private function isAskPassExecutable(?string $path): bool
    {
        if (! $path || ! file_exists($path)) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }

        if (! is_executable($path)) {
            return false;
        }

        $process = new Process([$path, 'Username'], null, array_merge($this->baseEnv(), [
            'GIT_USERNAME' => 'x-access-token',
            'GIT_PASSWORD' => 'token',
        ]));
        $process->run();

        return $process->isSuccessful();
    }
}
