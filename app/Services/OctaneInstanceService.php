<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class OctaneInstanceService
{
    public function __construct(private readonly DockerService $docker) {}

    /**
     * @return array{
     *     docker_available: bool,
     *     compose_available: bool,
     *     running: bool,
     *     state: string,
     *     status: string,
     *     name: string,
     *     port: int,
     *     url: string,
     *     message: string,
     *     operation: array<string, mixed>|null
     * }
     */
    public function status(): array
    {
        $port = $this->port();
        $base = [
            'docker_available' => $this->docker->isAvailable(),
            'compose_available' => false,
            'running' => false,
            'state' => 'unavailable',
            'status' => '',
            'name' => '',
            'port' => $port,
            'url' => $this->url($port),
            'message' => '',
            'operation' => $this->operationStatus(),
        ];

        if (! $base['docker_available']) {
            $base['message'] = $this->dockerUnavailableMessage();

            return $base;
        }

        $base['compose_available'] = $this->docker->composeAvailable();
        if (! $base['compose_available']) {
            $base['state'] = 'compose_unavailable';
            $base['message'] = $this->composeUnavailableMessage();

            return $base;
        }

        $status = $this->docker->composeServiceStatus($this->workingDirectory(), $this->serviceName());
        $running = (bool) ($status['running'] ?? false);

        return array_merge($base, [
            'running' => $running,
            'state' => (string) ($status['state'] ?? 'unknown'),
            'status' => (string) ($status['status'] ?? ''),
            'name' => (string) ($status['name'] ?? ''),
            'message' => $status['success'] ?? false
                ? ($running ? 'Octane is running.' : 'Octane is stopped or has not been created.')
                : (string) ($status['error'] ?? 'Unable to inspect the Octane service.'),
            'operation' => $this->reconciledOperationStatus($running),
        ]);
    }

    public function start(bool $build = true): array
    {
        if (! $this->docker->isAvailable()) {
            return ['success' => false, 'message' => $this->dockerUnavailableMessage()];
        }

        if (! $this->docker->composeAvailable()) {
            return ['success' => false, 'message' => $this->composeUnavailableMessage()];
        }

        $result = $this->docker->composeUp(
            $this->workingDirectory(),
            $this->serviceName(),
            [$this->profileName()],
            $build,
        );

        return [
            'success' => (bool) $result['success'],
            'message' => $result['success']
                ? 'Octane instance started.'
                : $this->composeErrorMessage($result, 'Octane instance could not be started.'),
        ];
    }

    public function startInBackground(bool $build = true): array
    {
        return $this->runInBackground('start', $build ? [] : ['--no-build']);
    }

    public function stop(): array
    {
        return $this->composeAction(
            fn () => $this->docker->composeStop($this->workingDirectory(), $this->serviceName()),
            'Octane instance stopped.',
            'Octane instance could not be stopped.',
        );
    }

    public function stopInBackground(): array
    {
        return $this->runInBackground('stop');
    }

    public function restart(): array
    {
        return $this->composeAction(
            fn () => $this->docker->composeRestart($this->workingDirectory(), $this->serviceName()),
            'Octane instance restarted.',
            'Octane instance could not be restarted.',
        );
    }

    public function restartInBackground(): array
    {
        return $this->runInBackground('restart');
    }

    /**
     * @param  array<int, string>  $extraArgs
     */
    private function runInBackground(string $action, array $extraArgs = []): array
    {
        if (! $this->docker->isAvailable()) {
            return ['success' => false, 'message' => $this->dockerUnavailableMessage()];
        }

        if (! $this->docker->composeAvailable()) {
            return ['success' => false, 'message' => $this->composeUnavailableMessage()];
        }

        if (app()->runningUnitTests()) {
            return ['success' => true, 'message' => $this->backgroundMessage($action)];
        }

        try {
            $this->ensureLogDirectory();
            $this->writeOperationStatus($action, 'queued', $this->backgroundMessage($action));

            $php = $this->phpBinary();
            $artisan = base_path('artisan');
            $args = array_merge(['octane:instance', $action], $extraArgs);
            $logPath = $this->logPath();

            if (PHP_OS_FAMILY === 'Windows') {
                $command = 'start "" /B '
                    .$this->cmdQuote($php).' '
                    .$this->cmdQuote($artisan).' '
                    .implode(' ', array_map([$this, 'cmdQuote'], $args))
                    .' >> '.$this->cmdQuote($logPath)
                    .' 2>> '.$this->cmdQuote($this->errorLogPath());

                $process = Process::fromShellCommandline($command, base_path());
            } else {
                $command = 'cd '.escapeshellarg(base_path())
                    .' && nohup '.escapeshellarg($php).' '.escapeshellarg($artisan).' '
                    .implode(' ', array_map('escapeshellarg', $args))
                    .' >> '.escapeshellarg($logPath).' 2>&1 &';

                $process = Process::fromShellCommandline($command, base_path());
            }

            $process->setTimeout(15);
            $process->run();

            if (! $process->isSuccessful()) {
                return [
                    'success' => false,
                    'message' => 'Unable to launch Octane background action. '.trim($process->getErrorOutput() ?: $process->getOutput()),
                ];
            }

            return ['success' => true, 'message' => $this->backgroundMessage($action)];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => 'Unable to launch Octane background action. '.$exception->getMessage()];
        }
    }

    private function backgroundMessage(string $action): string
    {
        return match ($action) {
            'start' => 'Octane start queued in the background. Check the Octane status panel in a moment.',
            'stop' => 'Octane stop queued in the background. Check the Octane status panel in a moment.',
            'restart' => 'Octane restart queued in the background. Check the Octane status panel in a moment.',
            default => 'Octane action queued in the background. Check the Octane status panel in a moment.',
        };
    }

    public function markOperationRunning(string $action): void
    {
        $this->writeOperationStatus($action, 'running', 'Octane '.$action.' is running in the background.');
    }

    public function markOperationFinished(string $action, bool $success, string $message): void
    {
        $this->writeOperationStatus($action, $success ? 'completed' : 'failed', $message, true);
    }

    private function composeAction(callable $action, string $successMessage, string $failureMessage): array
    {
        if (! $this->docker->isAvailable()) {
            return ['success' => false, 'message' => $this->dockerUnavailableMessage()];
        }

        if (! $this->docker->composeAvailable()) {
            return ['success' => false, 'message' => $this->composeUnavailableMessage()];
        }

        $result = $action();

        return [
            'success' => (bool) $result['success'],
            'message' => $result['success']
                ? $successMessage
                : $this->composeErrorMessage($result, $failureMessage),
        ];
    }

    private function composeErrorMessage(array $result, string $fallback): string
    {
        $error = trim((string) ($result['error'] ?? ''));
        $output = trim((string) ($result['output'] ?? ''));
        $message = $error ?: $output;

        if ($this->docker->isPermissionDeniedError($message)) {
            return $this->dockerPermissionDeniedMessage();
        }

        return $fallback.($message !== '' ? ' '.$message : '');
    }

    private function dockerUnavailableMessage(): string
    {
        $status = $this->docker->daemonStatus();
        $message = trim((string) ($status['error'] ?: $status['output']));

        if ($this->docker->isPermissionDeniedError($message)) {
            return $this->dockerPermissionDeniedMessage();
        }

        return 'Docker is not available on this server.'.($message !== '' ? ' '.$message : '');
    }

    private function dockerPermissionDeniedMessage(): string
    {
        return 'Docker is installed, but this app user cannot access the Docker daemon socket at /var/run/docker.sock. '
            .'Run the web/PHP worker under a user with Docker access, add that user to the docker group and restart the PHP/web service, '
            .'or expose Docker through a controlled remote context.';
    }

    private function composeUnavailableMessage(): string
    {
        $status = $this->docker->composeStatus();
        $message = trim((string) ($status['error'] ?: $status['output']));

        return 'Docker is available, but Docker Compose support was not detected. '
            .'Install the Docker Compose v2 plugin, install standalone docker-compose, or set GWM_DOCKER_COMPOSE_BINARY to its absolute path.'
            .($message !== '' ? ' '.$message : '');
    }

    private function workingDirectory(): string
    {
        return base_path();
    }

    private function phpBinary(): string
    {
        $configured = trim((string) config('gitmanager.php_binary', 'php'));
        $configured = trim($configured, "\"' ");

        if ($configured !== '' && $configured !== 'php') {
            return $configured;
        }

        foreach ($this->phpCliCandidates($configured) as $candidate) {
            if ($this->isUsablePhpCliBinary($candidate)) {
                return $candidate;
            }
        }

        return $configured !== '' ? $configured : 'php';
    }

    /**
     * @return array<int, string>
     */
    private function phpCliCandidates(string $configured): array
    {
        $candidates = [];

        if ($configured !== '') {
            $candidates[] = $configured;
        }

        if (PHP_BINARY !== '') {
            $candidates[] = PHP_BINARY;

            $fpmMapped = str_replace(['/php-fpm83/', '/php-fpm82/', '/php-fpm81/'], ['/php83/', '/php82/', '/php81/'], PHP_BINARY);
            $fpmMapped = preg_replace('#/sbin/php-fpm(?:[0-9.]*)?$#', '/bin/php', $fpmMapped) ?: $fpmMapped;
            if ($fpmMapped !== PHP_BINARY) {
                $candidates[] = $fpmMapped;
            }
        }

        $candidates[] = '/opt/alt/php83/usr/bin/php';
        $candidates[] = '/opt/alt/php82/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        $candidates[] = 'php';

        return array_values(array_unique(array_filter($candidates)));
    }

    private function isUsablePhpCliBinary(string $binary): bool
    {
        $name = strtolower(basename($binary));
        if (str_contains($name, 'php-fpm') || str_contains($name, 'php-cgi')) {
            return false;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) && (! is_file($binary) || ! is_executable($binary))) {
            return false;
        }

        return true;
    }

    private function serviceName(): string
    {
        return (string) config('gitmanager.octane.service', 'octane');
    }

    private function profileName(): string
    {
        return (string) config('gitmanager.octane.profile', 'octane');
    }

    private function port(): int
    {
        return max(1, min(65535, (int) config('gitmanager.octane.port', 8000)));
    }

    private function url(int $port): string
    {
        $host = trim((string) config('gitmanager.octane.host', '127.0.0.1'));

        return 'http://'.$host.':'.$port;
    }

    private function ensureLogDirectory(): void
    {
        $directory = dirname($this->logPath());

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }

    private function logPath(): string
    {
        return storage_path('logs/octane-instance.log');
    }

    private function errorLogPath(): string
    {
        return storage_path('logs/octane-instance-error.log');
    }

    private function operationStatusPath(): string
    {
        return storage_path('framework/octane-instance-operation.json');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function operationStatus(): ?array
    {
        $path = $this->operationStatusPath();
        if (! is_file($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reconciledOperationStatus(bool $running): ?array
    {
        $operation = $this->operationStatus();
        if (! is_array($operation)) {
            return null;
        }

        $state = (string) ($operation['state'] ?? '');
        $action = (string) ($operation['action'] ?? '');

        if ($running && $state === 'failed' && in_array($action, ['start', 'restart'], true)) {
            return [
                ...$operation,
                'state' => 'completed',
                'message' => 'Octane is running. The previous failed action has been superseded by the current container state.',
            ];
        }

        return $operation;
    }

    private function writeOperationStatus(string $action, string $state, string $message, bool $finished = false): void
    {
        $previous = $this->operationStatus();
        $now = now()->toDateTimeString();

        $payload = [
            'action' => $action,
            'state' => $state,
            'message' => $message,
            'started_at' => is_array($previous) && ($previous['action'] ?? null) === $action
                ? (string) ($previous['started_at'] ?? $now)
                : $now,
            'updated_at' => $now,
            'finished_at' => $finished ? $now : null,
            'log_path' => $this->logPath(),
            'error_log_path' => $this->errorLogPath(),
        ];

        @file_put_contents($this->operationStatusPath(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function cmdQuote(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
