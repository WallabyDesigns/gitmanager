<?php

namespace App\Services;

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
            $base['message'] = 'Docker is not available on this server.';

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
            return ['success' => false, 'message' => 'Docker is not available on this server.'];
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

    public function stop(): array
    {
        return $this->composeAction(
            fn () => $this->docker->composeStop($this->workingDirectory(), $this->serviceName()),
            'Octane instance stopped.',
            'Octane instance could not be stopped.',
        );
    }

    public function restart(): array
    {
        return $this->composeAction(
            fn () => $this->docker->composeRestart($this->workingDirectory(), $this->serviceName()),
            'Octane instance restarted.',
            'Octane instance could not be restarted.',
        );
    }

    private function composeAction(callable $action, string $successMessage, string $failureMessage): array
    {
        if (! $this->docker->isAvailable()) {
            return ['success' => false, 'message' => 'Docker is not available on this server.'];
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

        return $fallback.(($error ?: $output) !== '' ? ' '.($error ?: $output) : '');
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
}
