<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class RustExecutorInstanceService
{
    public function __construct(private readonly DockerService $docker) {}

    /**
     * @return array<string, bool|int|string|array|null>
     */
    public function status(): array
    {
        $port = $this->port();
        $status = [
            'docker_available' => $this->docker->isAvailable(),
            'compose_available' => false,
            'running' => false,
            'state' => 'unavailable',
            'status' => '',
            'endpoint' => 'http://'.$this->serviceName().':'.$port.'/health',
            'message' => '',
            'operation' => $this->operationStatus(),
        ];

        if (! $status['docker_available']) {
            $status['message'] = $this->dockerUnavailableMessage();

            return $status;
        }

        $status['compose_available'] = $this->docker->composeAvailable();
        if (! $status['compose_available']) {
            $status['state'] = 'compose_unavailable';
            $status['message'] = $this->composeUnavailableMessage();

            return $status;
        }

        $composeStatus = $this->docker->composeServiceStatus(base_path(), $this->serviceName());
        $running = (bool) ($composeStatus['running'] ?? false);

        return array_merge($status, [
            'running' => $running,
            'state' => (string) ($composeStatus['state'] ?? 'unknown'),
            'status' => (string) ($composeStatus['status'] ?? ''),
            'message' => ($composeStatus['success'] ?? false)
                ? ($running ? __('Rust operations executor is running.') : __('Rust operations executor has not been installed.'))
                : (string) ($composeStatus['error'] ?? __('Unable to inspect the Rust operations executor.')),
        ]);
    }

    /** @return array{success: bool, message: string} */
    public function start(bool $build = true): array
    {
        return $this->composeAction(
            fn (): array => $this->docker->composeUp(base_path(), $this->serviceName(), [$this->profileName()], $build),
            __('Rust operations executor installed and started.'),
            __('Rust operations executor could not be installed.'),
        );
    }

    /** @return array{success: bool, message: string} */
    public function stop(): array
    {
        return $this->composeAction(
            fn (): array => $this->docker->composeStop(base_path(), $this->serviceName()),
            __('Rust operations executor stopped.'),
            __('Rust operations executor could not be stopped.'),
        );
    }

    /** @return array{success: bool, message: string} */
    public function restart(): array
    {
        return $this->composeAction(
            fn (): array => $this->docker->composeRestart(base_path(), $this->serviceName()),
            __('Rust operations executor restarted.'),
            __('Rust operations executor could not be restarted.'),
        );
    }

    /** @return array{success: bool, message: string} */
    public function startInBackground(): array
    {
        return $this->runInBackground('start');
    }

    /** @return array{success: bool, message: string} */
    public function stopInBackground(): array
    {
        return $this->runInBackground('stop');
    }

    /** @return array{success: bool, message: string} */
    public function restartInBackground(): array
    {
        return $this->runInBackground('restart');
    }

    public function markOperationRunning(string $action): void
    {
        $this->writeOperationStatus($action, 'running', __('Rust operations executor :action is running in the background.', ['action' => $action]));
    }

    public function markOperationFinished(string $action, bool $success, string $message): void
    {
        $this->writeOperationStatus($action, $success ? 'completed' : 'failed', $message, true);
    }

    /** @return array{success: bool, message: string} */
    private function runInBackground(string $action): array
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

        $this->ensureLogDirectory();
        $this->writeOperationStatus($action, 'queued', $this->backgroundMessage($action));

        try {
            $php = $this->phpBinary();
            $artisan = base_path('artisan');
            $log = $this->logPath();

            if (PHP_OS_FAMILY === 'Windows') {
                $command = 'start "" /B '.$this->cmdQuote($php).' '.$this->cmdQuote($artisan).' rust-executor:instance '.$this->cmdQuote($action)
                    .' >> '.$this->cmdQuote($log).' 2>&1';
            } else {
                $command = 'cd '.escapeshellarg(base_path()).' && nohup '.escapeshellarg($php).' '.escapeshellarg($artisan).' rust-executor:instance '.escapeshellarg($action)
                    .' >> '.escapeshellarg($log).' 2>&1 &';
            }

            $process = Process::fromShellCommandline($command, base_path());
            $process->setTimeout(15);
            $process->run();

            if (! $process->isSuccessful()) {
                $message = trim($process->getErrorOutput() ?: $process->getOutput());
                $this->markOperationFinished($action, false, __('Unable to launch the Rust executor background action.').' '.$message);

                return ['success' => false, 'message' => __('Unable to launch the Rust executor background action.').' '.$message];
            }

            return ['success' => true, 'message' => $this->backgroundMessage($action)];
        } catch (\Throwable $exception) {
            $message = __('Unable to launch the Rust executor background action.').' '.$exception->getMessage();
            $this->markOperationFinished($action, false, $message);

            return ['success' => false, 'message' => $message];
        }
    }

    /** @return array{success: bool, message: string} */
    private function composeAction(callable $action, string $successMessage, string $failureMessage): array
    {
        if (! $this->docker->isAvailable()) {
            return ['success' => false, 'message' => $this->dockerUnavailableMessage()];
        }

        if (! $this->docker->composeAvailable()) {
            return ['success' => false, 'message' => $this->composeUnavailableMessage()];
        }

        $result = $action();
        if ($result['success']) {
            return ['success' => true, 'message' => $successMessage];
        }

        $detail = trim((string) ($result['error'] ?? '')."\n".(string) ($result['output'] ?? ''));

        return ['success' => false, 'message' => $failureMessage.($detail !== '' ? ' '.$this->summarize($detail) : '')];
    }

    private function backgroundMessage(string $action): string
    {
        return match ($action) {
            'start' => __('Rust executor installation is queued in the background. Check its status in a moment.'),
            'stop' => __('Rust executor stop is queued in the background. Check its status in a moment.'),
            'restart' => __('Rust executor restart is queued in the background. Check its status in a moment.'),
            default => __('Rust executor action is queued in the background.'),
        };
    }

    private function dockerUnavailableMessage(): string
    {
        $status = $this->docker->daemonStatus();
        $detail = trim((string) ($status['error'] ?: $status['output']));

        return __('Docker is not available on this server.').($detail !== '' ? ' '.$detail : '');
    }

    private function composeUnavailableMessage(): string
    {
        return __('Docker is available, but Docker Compose support was not detected. Install the Docker Compose v2 plugin, install standalone docker-compose, or set GWM_DOCKER_COMPOSE_BINARY to its absolute path.');
    }

    private function serviceName(): string
    {
        return (string) config('gitmanager.rust_executor.service', 'rust-executor');
    }

    private function profileName(): string
    {
        return (string) config('gitmanager.rust_executor.profile', 'rust-executor');
    }

    private function port(): int
    {
        return max(1, min(65535, (int) config('gitmanager.rust_executor.port', 8787)));
    }

    private function phpBinary(): string
    {
        $configured = trim((string) config('gitmanager.php_binary', PHP_BINARY ?: 'php'), "\"' ");

        return $configured !== '' ? $configured : 'php';
    }

    private function ensureLogDirectory(): void
    {
        if (! is_dir(dirname($this->logPath()))) {
            @mkdir(dirname($this->logPath()), 0775, true);
        }
    }

    private function logPath(): string
    {
        return storage_path('logs/rust-executor-instance.log');
    }

    /** @return array<string, mixed>|null */
    private function operationStatus(): ?array
    {
        if (! is_file($this->operationStatusPath())) {
            return null;
        }

        $status = json_decode((string) file_get_contents($this->operationStatusPath()), true);

        return is_array($status) ? $status : null;
    }

    private function writeOperationStatus(string $action, string $state, string $message, bool $finished = false): void
    {
        $now = now()->toDateTimeString();
        @file_put_contents($this->operationStatusPath(), json_encode([
            'action' => $action,
            'state' => $state,
            'message' => $message,
            'updated_at' => $now,
            'finished_at' => $finished ? $now : null,
            'log_path' => $this->logPath(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function operationStatusPath(): string
    {
        return storage_path('framework/rust-executor-instance-operation.json');
    }

    private function summarize(string $message): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $message) ?: [])));

        return implode("\n", array_slice($lines, -40));
    }

    private function cmdQuote(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
