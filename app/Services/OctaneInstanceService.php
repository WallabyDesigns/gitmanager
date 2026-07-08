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
     *     message: string
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
        ];

        if (! $base['docker_available']) {
            $base['message'] = $this->dockerUnavailableMessage();

            return $base;
        }

        $base['compose_available'] = $this->docker->composeAvailable();
        if (! $base['compose_available']) {
            $base['state'] = 'compose_unavailable';
            $base['message'] = 'Docker is available, but Docker Compose support was not detected.';

            return $base;
        }

        $status = $this->docker->composeServiceStatus($this->workingDirectory(), $this->serviceName());

        return array_merge($base, [
            'running' => (bool) ($status['running'] ?? false),
            'state' => (string) ($status['state'] ?? 'unknown'),
            'status' => (string) ($status['status'] ?? ''),
            'name' => (string) ($status['name'] ?? ''),
            'message' => $status['success'] ?? false
                ? (($status['running'] ?? false) ? 'Octane is running.' : 'Octane is stopped or has not been created.')
                : (string) ($status['error'] ?? 'Unable to inspect the Octane service.'),
        ]);
    }

    public function start(bool $build = true): array
    {
        if (! $this->docker->isAvailable()) {
            return ['success' => false, 'message' => $this->dockerUnavailableMessage()];
        }

        if (! $this->docker->composeAvailable()) {
            return ['success' => false, 'message' => 'Docker Compose support was not detected.'];
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
            return ['success' => false, 'message' => 'Docker Compose support was not detected.'];
        }

        if (app()->runningUnitTests()) {
            return ['success' => true, 'message' => $this->backgroundMessage($action)];
        }

        try {
            $this->ensureLogDirectory();

            $php = PHP_BINARY;
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

    private function composeAction(callable $action, string $successMessage, string $failureMessage): array
    {
        if (! $this->docker->isAvailable()) {
            return ['success' => false, 'message' => $this->dockerUnavailableMessage()];
        }

        if (! $this->docker->composeAvailable()) {
            return ['success' => false, 'message' => 'Docker Compose support was not detected.'];
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

    private function workingDirectory(): string
    {
        return base_path();
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

    private function cmdQuote(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
