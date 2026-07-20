<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

class DockerService
{
    private string $binary;

    /**
     * @var array{type: string, binary: string}|null
     */
    private ?array $composeCommand = null;

    public function __construct()
    {
        $this->binary = $this->resolveDockerBinary((string) config('gitmanager.docker.binary', 'docker'));
    }

    public function isAvailable(): bool
    {
        $status = $this->daemonStatus();

        return (bool) $status['success'];
    }

    /**
     * @return array{success: bool, output: string, error: string}
     */
    public function daemonStatus(): array
    {
        [$success, $output, $error] = $this->run(['info', '--format', '{{.ServerVersion}}']);

        return ['success' => $success, 'output' => $output, 'error' => $error];
    }

    public function isPermissionDeniedError(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'permission denied')
            && (str_contains($message, 'docker.sock') || str_contains($message, 'docker daemon socket'));
    }

    public function composeAvailable(): bool
    {
        $status = $this->composeStatus();

        return (bool) $status['success'];
    }

    /**
     * @return array{success: bool, output: string, error: string}
     */
    public function composeStatus(): array
    {
        $command = $this->composeCommand();
        if ($command !== null) {
            return ['success' => true, 'output' => $command['type'], 'error' => ''];
        }

        [$dockerComposeSuccess, $dockerComposeOutput, $dockerComposeError] = $this->run(['compose', 'version', '--short']);
        [$standaloneSuccess, $standaloneOutput, $standaloneError] = $this->runBinary($this->resolveDockerComposeBinary(), ['version', '--short']);

        return [
            'success' => false,
            'output' => trim($dockerComposeOutput."\n".$standaloneOutput),
            'error' => trim(
                'docker compose: '.($dockerComposeError ?: 'not available')."\n".
                'docker-compose: '.($standaloneError ?: 'not available')
            ),
        ];
    }

    // ─── Containers ───────────────────────────────────────────────────────────

    public function listContainers(bool $all = true): array
    {
        $args = ['ps', '--format', 'json', '--no-trunc'];
        if ($all) {
            $args[] = '-a';
        }

        [$success, $output] = $this->run($args);
        if (! $success) {
            return [];
        }

        return $this->parseJsonLines($output);
    }

    public function inspectContainer(string $id): array
    {
        [$success, $output] = $this->run(['inspect', $id]);
        if (! $success) {
            return [];
        }

        $data = json_decode($output, true);

        return $data[0] ?? [];
    }

    public function startContainer(string $id): array
    {
        [$success, , $error] = $this->run(['start', $id]);

        return ['success' => $success, 'error' => $error];
    }

    public function stopContainer(string $id): array
    {
        [$success, , $error] = $this->run(['stop', $id]);

        return ['success' => $success, 'error' => $error];
    }

    public function restartContainer(string $id): array
    {
        [$success, , $error] = $this->run(['restart', $id]);

        return ['success' => $success, 'error' => $error];
    }

    public function removeContainer(string $id, bool $force = false): array
    {
        $args = ['rm'];
        if ($force) {
            $args[] = '-f';
        }
        $args[] = $id;

        [$success, , $error] = $this->run($args);

        return ['success' => $success, 'error' => $error];
    }

    public function createContainer(array $options): array
    {
        $args = ['run', '-d'];

        if (! empty($options['name'])) {
            $args[] = '--name';
            $args[] = $options['name'];
        }

        if (! empty($options['restart'])) {
            $args[] = '--restart';
            $args[] = $options['restart'];
        }

        foreach ($options['ports'] ?? [] as $port) {
            if (trim($port) !== '') {
                $args[] = '-p';
                $args[] = trim($port);
            }
        }

        foreach ($options['env'] ?? [] as $env) {
            if (trim($env) !== '') {
                $args[] = '-e';
                $args[] = trim($env);
            }
        }

        foreach ($options['volumes'] ?? [] as $vol) {
            if (trim($vol) !== '') {
                $args[] = '-v';
                $args[] = trim($vol);
            }
        }

        if (! empty($options['network'])) {
            $args[] = '--network';
            $args[] = $options['network'];
        }

        if (! empty($options['memory'])) {
            $args[] = '-m';
            $args[] = $options['memory'];
        }

        if (! empty($options['cpus'])) {
            $args[] = '--cpus';
            $args[] = $options['cpus'];
        }

        if (! empty($options['hostname'])) {
            $args[] = '--hostname';
            $args[] = $options['hostname'];
        }

        $args[] = $options['image'];

        if (! empty($options['command'])) {
            foreach ($this->tokenizeCommand($options['command']) as $part) {
                $args[] = $part;
            }
        }

        [$success, $output, $error] = $this->run($args);

        return ['success' => $success, 'id' => trim($output), 'error' => $error];
    }

    public function updateContainer(string $id, array $options): array
    {
        $args = ['update'];

        if (! empty($options['memory'])) {
            $args[] = '--memory';
            $args[] = $options['memory'];
        }

        if (! empty($options['cpus'])) {
            $args[] = '--cpus';
            $args[] = $options['cpus'];
        }

        if (! empty($options['restart'])) {
            $args[] = '--restart';
            $args[] = $options['restart'];
        }

        $args[] = $id;

        [$success, , $error] = $this->run($args);

        return ['success' => $success, 'error' => $error];
    }

    public function getContainerLogs(string $id, int $tail = 150): string
    {
        [$success, $output, $error] = $this->run(['logs', '--tail', (string) $tail, '--timestamps', $id]);

        return $success ? $output : $error;
    }

    public function getAllContainerStats(): array
    {
        [$success, $output] = $this->run(['stats', '--no-stream', '--format', 'json']);
        if (! $success) {
            return [];
        }

        return $this->parseJsonLines($output);
    }

    public function getContainerStats(string $id): array
    {
        [$success, $output] = $this->run(['stats', '--no-stream', '--format', 'json', $id]);
        if (! $success) {
            return [];
        }

        $lines = $this->parseJsonLines($output);

        return $lines[0] ?? [];
    }

    // ─── Images ───────────────────────────────────────────────────────────────

    public function listImages(): array
    {
        [$success, $output] = $this->run(['images', '--format', 'json']);
        if (! $success) {
            return [];
        }

        return $this->parseJsonLines($output);
    }

    public function pullImage(string $image): array
    {
        [$success, $output, $error] = $this->run(['pull', $image]);

        return ['success' => $success, 'output' => $output, 'error' => $error];
    }

    public function removeImage(string $id, bool $force = false): array
    {
        $args = ['rmi'];
        if ($force) {
            $args[] = '-f';
        }
        $args[] = $id;

        [$success, , $error] = $this->run($args);

        return ['success' => $success, 'error' => $error];
    }

    public function inspectImage(string $id): array
    {
        [$success, $output] = $this->run(['inspect', $id]);
        if (! $success) {
            return [];
        }

        $data = json_decode($output, true);

        return $data[0] ?? [];
    }

    public function tagImage(string $source, string $target): array
    {
        $source = trim($source);
        $target = trim($target);

        if ($source === '' || $target === '') {
            return ['success' => false, 'error' => 'Source image and target tag are required.'];
        }

        [$success, , $error] = $this->run(['tag', $source, $target]);

        return ['success' => $success, 'error' => $error];
    }

    public function buildImage(string $contextPath, string $dockerfilePath, string $imageName, string $tag = 'latest'): array
    {
        $contextPath = trim($contextPath);
        $dockerfilePath = trim($dockerfilePath);
        $imageName = trim($imageName);
        $tag = trim($tag);

        $inputError = match (true) {
            $contextPath === '' || ! is_dir($contextPath) => 'Build context path does not exist.',
            $dockerfilePath === '' || ! is_file($dockerfilePath) => 'Dockerfile not found at the specified path.',
            $imageName === '' => 'Image name is required.',
            default => '',
        };

        if ($inputError !== '') {
            return ['success' => false, 'error' => $inputError];
        }

        $reference = $tag !== '' ? $imageName.':'.$tag : $imageName;
        [$success, $output, $error] = $this->run(['build', '-t', $reference, '-f', $dockerfilePath, $contextPath]);

        return ['success' => $success, 'output' => $output, 'error' => $error];
    }

    // ─── Volumes ──────────────────────────────────────────────────────────────

    public function listVolumes(): array
    {
        [$success, $output] = $this->run(['volume', 'ls', '--format', 'json']);
        if (! $success) {
            return [];
        }

        return $this->parseJsonLines($output);
    }

    public function createVolume(string $name, string $driver = 'local'): array
    {
        [$success, , $error] = $this->run(['volume', 'create', '--driver', $driver, $name]);

        return ['success' => $success, 'error' => $error];
    }

    public function removeVolume(string $name, bool $force = false): array
    {
        $args = ['volume', 'rm'];
        if ($force) {
            $args[] = '-f';
        }
        $args[] = $name;

        [$success, , $error] = $this->run($args);

        return ['success' => $success, 'error' => $error];
    }

    public function inspectVolume(string $name): array
    {
        [$success, $output] = $this->run(['volume', 'inspect', $name]);
        if (! $success) {
            return [];
        }

        $data = json_decode($output, true);

        return $data[0] ?? [];
    }

    // ─── Networks ─────────────────────────────────────────────────────────────

    public function listNetworks(): array
    {
        [$success, $output] = $this->run(['network', 'ls', '--format', 'json']);
        if (! $success) {
            return [];
        }

        return $this->parseJsonLines($output);
    }

    public function createNetwork(string $name, string $driver = 'bridge'): array
    {
        [$success, , $error] = $this->run(['network', 'create', '--driver', $driver, $name]);

        return ['success' => $success, 'error' => $error];
    }

    public function removeNetwork(string $name): array
    {
        [$success, , $error] = $this->run(['network', 'rm', $name]);

        return ['success' => $success, 'error' => $error];
    }

    public function inspectNetwork(string $name): array
    {
        [$success, $output] = $this->run(['network', 'inspect', $name]);
        if (! $success) {
            return [];
        }

        $data = json_decode($output, true);

        return $data[0] ?? [];
    }

    public function cloneNetwork(string $sourceName, string $targetName, ?string $driver = null): array
    {
        $sourceName = trim($sourceName);
        $targetName = trim($targetName);

        if ($sourceName === '' || $targetName === '') {
            return ['success' => false, 'error' => 'Source and target network names are required.'];
        }

        $inspect = $this->inspectNetwork($sourceName);
        if ($inspect === []) {
            return ['success' => false, 'error' => 'Source network could not be inspected.'];
        }

        $resolvedDriver = trim((string) ($driver ?? $inspect['Driver'] ?? 'bridge'));
        if ($resolvedDriver === '') {
            $resolvedDriver = 'bridge';
        }

        $args = ['network', 'create', '--driver', $resolvedDriver];

        if ((bool) ($inspect['Internal'] ?? false)) {
            $args[] = '--internal';
        }

        if ((bool) ($inspect['Attachable'] ?? false)) {
            $args[] = '--attachable';
        }

        if ((bool) ($inspect['EnableIPv6'] ?? false)) {
            $args[] = '--ipv6';
        }

        $args[] = $targetName;

        [$success, , $error] = $this->run($args);

        return ['success' => $success, 'error' => $error];
    }

    // ─── Swarm ────────────────────────────────────────────────────────────────

    public function getSwarmInfo(): array
    {
        [$success, $output] = $this->run(['info', '--format', '{{json .Swarm}}']);
        if (! $success) {
            return ['active' => false];
        }

        $data = json_decode(trim($output), true);
        if (! is_array($data) || ($data['LocalNodeState'] ?? '') !== 'active') {
            return ['active' => false];
        }

        return array_merge($data, ['active' => true]);
    }

    public function initSwarm(string $advertiseAddr = ''): array
    {
        $args = ['swarm', 'init'];
        if ($advertiseAddr !== '') {
            $args[] = '--advertise-addr';
            $args[] = $advertiseAddr;
        }

        [$success, $output, $error] = $this->run($args);

        return ['success' => $success, 'output' => $output, 'error' => $error];
    }

    public function listSwarmNodes(): array
    {
        [$success, $output] = $this->run(['node', 'ls', '--format', 'json']);
        if (! $success) {
            return [];
        }

        return $this->parseJsonLines($output);
    }

    public function listSwarmServices(): array
    {
        [$success, $output] = $this->run(['service', 'ls', '--format', 'json']);
        if (! $success) {
            return [];
        }

        return $this->parseJsonLines($output);
    }

    public function scaleService(string $service, int $replicas): array
    {
        [$success, , $error] = $this->run(['service', 'scale', "{$service}={$replicas}"]);

        return ['success' => $success, 'error' => $error];
    }

    public function getServiceTasks(string $service): array
    {
        [$success, $output] = $this->run(['service', 'ps', '--format', 'json', $service]);
        if (! $success) {
            return [];
        }

        return $this->parseJsonLines($output);
    }

    // ─── Compose ─────────────────────────────────────────────────────────────

    public function composeUp(string $workingDirectory, string $service, array $profiles = [], bool $build = false): array
    {
        $args = [];
        foreach ($profiles as $profile) {
            $profile = trim((string) $profile);
            if ($profile !== '') {
                $args[] = '--profile';
                $args[] = $profile;
            }
        }

        $args[] = 'up';
        $args[] = '-d';

        if ($build) {
            $args[] = '--build';
        }

        $args[] = $service;

        [$success, $output, $error] = $this->runCompose($args, $workingDirectory);

        return ['success' => $success, 'output' => $output, 'error' => $error];
    }

    public function composeStop(string $workingDirectory, string $service): array
    {
        [$success, $output, $error] = $this->runCompose(['stop', $service], $workingDirectory);

        return ['success' => $success, 'output' => $output, 'error' => $error];
    }

    public function composeRestart(string $workingDirectory, string $service): array
    {
        [$success, $output, $error] = $this->runCompose(['restart', $service], $workingDirectory);

        return ['success' => $success, 'output' => $output, 'error' => $error];
    }

    public function composeServiceStatus(string $workingDirectory, string $service): array
    {
        [$success, $output, $error] = $this->runCompose(['ps', '--format', 'json', $service], $workingDirectory);
        if (! $success) {
            return [
                'success' => false,
                'service' => $service,
                'running' => false,
                'state' => 'unknown',
                'status' => '',
                'error' => $error,
            ];
        }

        $rows = $this->parseComposeJson($output);
        $row = $rows[0] ?? [];
        $state = strtolower((string) ($row['State'] ?? ''));

        return [
            'success' => true,
            'service' => $service,
            'running' => $state === 'running',
            'state' => $state !== '' ? $state : 'not_created',
            'status' => (string) ($row['Status'] ?? ''),
            'name' => (string) ($row['Name'] ?? ''),
            'publishers' => $row['Publishers'] ?? [],
            'error' => '',
        ];
    }

    // ─── CLI Parser ───────────────────────────────────────────────────────────

    public function parseRunCommand(string $command): array
    {
        $command = preg_replace('/\\\\\s*(\r\n|\r|\n)\s*/', ' ', $command) ?? $command;
        $command = preg_replace('/\s*(\r\n|\r|\n)\s*/', ' ', $command) ?? $command;
        $command = preg_replace('/^docker\s+run\s+/i', '', trim($command));

        $result = [
            'image' => '',
            'name' => '',
            'ports' => [],
            'env' => [],
            'volumes' => [],
            'network' => '',
            'restart' => '',
            'memory' => '',
            'cpus' => '',
            'command' => '',
            'hostname' => '',
        ];

        $tokens = $this->tokenizeCommand($command);
        $i = 0;
        $imageFound = false;

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if ($token === '--name') {
                $result['name'] = $tokens[++$i] ?? '';
            } elseif (str_starts_with($token, '--name=')) {
                $result['name'] = substr($token, 7);
            } elseif ($token === '-p' || $token === '--publish') {
                $val = $tokens[++$i] ?? '';
                if ($val !== '') {
                    $result['ports'][] = $val;
                }
            } elseif (str_starts_with($token, '-p') && strlen($token) > 2) {
                $result['ports'][] = substr($token, 2);
            } elseif ($token === '-e' || $token === '--env') {
                $val = $tokens[++$i] ?? '';
                if ($val !== '') {
                    $result['env'][] = $val;
                }
            } elseif (str_starts_with($token, '-e') && strlen($token) > 2) {
                $result['env'][] = substr($token, 2);
            } elseif ($token === '-v' || $token === '--volume') {
                $val = $tokens[++$i] ?? '';
                if ($val !== '') {
                    $result['volumes'][] = $val;
                }
            } elseif ($token === '--network') {
                $result['network'] = $tokens[++$i] ?? '';
            } elseif (str_starts_with($token, '--network=')) {
                $result['network'] = substr($token, 10);
            } elseif ($token === '--restart') {
                $result['restart'] = $tokens[++$i] ?? '';
            } elseif (str_starts_with($token, '--restart=')) {
                $result['restart'] = substr($token, 10);
            } elseif ($token === '-m' || $token === '--memory') {
                $result['memory'] = $tokens[++$i] ?? '';
            } elseif ($token === '--cpus') {
                $result['cpus'] = $tokens[++$i] ?? '';
            } elseif ($token === '-h' || $token === '--hostname') {
                $result['hostname'] = $tokens[++$i] ?? '';
            } elseif (in_array($token, ['-d', '--detach', '--rm', '-it', '-i', '-t', '--pull'], true)) {
                // skip boolean flags
            } elseif (! $imageFound && ! str_starts_with($token, '-')) {
                $result['image'] = $token;
                $imageFound = true;
            } elseif ($imageFound) {
                $result['command'] .= ($result['command'] !== '' ? ' ' : '').$token;
            }

            $i++;
        }

        return $result;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function parseJsonLines(string $output): array
    {
        $items = [];
        foreach (array_filter(explode("\n", trim($output))) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $data = json_decode($line, true);
            if (is_array($data)) {
                $items[] = $data;
            }
        }

        return $items;
    }

    private function parseComposeJson(string $output): array
    {
        $output = trim($output);
        if ($output === '') {
            return [];
        }

        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            if (array_is_list($decoded)) {
                return array_values(array_filter($decoded, 'is_array'));
            }

            return [$decoded];
        }

        return $this->parseJsonLines($output);
    }

    private function tokenizeCommand(string $command): array
    {
        $tokens = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';

        for ($i = 0; $i < strlen($command); $i++) {
            $char = $command[$i];

            if ($inQuote) {
                if ($char === $quoteChar) {
                    $inQuote = false;
                } else {
                    $current .= $char;
                }
            } elseif ($char === '"' || $char === "'") {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($char === ' ' || $char === "\t") {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    private function run(array $args, ?string $workingDirectory = null, ?array $environment = null): array
    {
        return $this->runBinary($this->binary, $args, $workingDirectory, $environment);
    }

    private function runCompose(array $args, ?string $workingDirectory = null): array
    {
        $command = $this->composeCommand();
        if ($command === null) {
            $status = $this->composeStatus();

            return [false, $status['output'], $status['error']];
        }

        if ($command['type'] === 'plugin') {
            return $this->run(array_merge(['compose'], $args), $workingDirectory, $this->composeEnvironment());
        }

        return $this->runBinary($command['binary'], $args, $workingDirectory, $this->composeEnvironment());
    }

    /**
     * @return array{type: string, binary: string}|null
     */
    private function composeCommand(): ?array
    {
        if ($this->composeCommand !== null) {
            return $this->composeCommand;
        }

        [$pluginSuccess] = $this->run(['compose', 'version', '--short']);
        if ($pluginSuccess) {
            return $this->composeCommand = ['type' => 'plugin', 'binary' => $this->binary];
        }

        $standalone = $this->resolveDockerComposeBinary();
        [$standaloneSuccess] = $this->runBinary($standalone, ['version', '--short']);
        if ($standaloneSuccess) {
            return $this->composeCommand = ['type' => 'standalone', 'binary' => $standalone];
        }

        return null;
    }

    private function runBinary(string $binary, array $args, ?string $workingDirectory = null, ?array $environment = null): array
    {
        $cmd = array_merge([$binary], $args);

        $pipes = [];

        set_error_handler(static fn () => true);

        try {
            $process = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $workingDirectory,
                $environment,
                ['bypass_shell' => true],
            );
        } catch (Throwable) {
            $process = false;
        } finally {
            restore_error_handler();
        }

        if (! is_resource($process)) {
            return [false, '', "Docker binary [{$this->binary}] was not found or is not executable."];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [$code === 0, (string) $stdout, (string) $stderr];
    }

    /**
     * Compose otherwise parses Laravel's .env as its own environment file.
     * Secrets containing dollar signs are valid Laravel values but Compose
     * treats them as interpolation expressions. Keep the inherited process
     * environment for build secrets and pass Compose settings explicitly.
     *
     * @return array<string, string>
     */
    private function composeEnvironment(): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];

        $environment['COMPOSE_DISABLE_ENV_FILE'] = '1';
        $environment['APP_PORT'] = (string) env('APP_PORT', 8080);
        $environment['OCTANE_PORT'] = (string) config('gitmanager.octane.port', 8000);
        $environment['GWM_RUST_EXECUTOR_LOG_LEVEL'] = (string) env('GWM_RUST_EXECUTOR_LOG_LEVEL', 'info');

        return array_filter($environment, static fn (mixed $value): bool => is_scalar($value));
    }

    private function resolveDockerBinary(string $configured): string
    {
        $configured = trim($configured, "\"' ");
        if ($configured === '') {
            $configured = 'docker';
        }

        if (strtolower(basename($configured)) !== 'docker' && strtolower(basename($configured)) !== 'docker.exe') {
            return $configured;
        }

        foreach ($this->dockerBinaryCandidates($configured) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return $configured;
    }

    private function resolveDockerComposeBinary(): string
    {
        $configured = trim((string) config('gitmanager.docker.compose_binary', 'docker-compose'), "\"' ");
        if ($configured === '') {
            $configured = 'docker-compose';
        }

        if (strtolower(basename($configured)) !== 'docker-compose' && strtolower(basename($configured)) !== 'docker-compose.exe') {
            return $configured;
        }

        foreach ($this->dockerComposeBinaryCandidates($configured) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return $configured;
    }

    /**
     * @return array<int, string>
     */
    private function dockerComposeBinaryCandidates(string $configured): array
    {
        $candidates = [$configured];

        if (PHP_OS_FAMILY === 'Windows') {
            $programFiles = getenv('ProgramFiles') ?: 'C:\\Program Files';
            $programFilesX86 = getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)';
            $candidates[] = $programFiles.'\\Docker\\Docker\\resources\\bin\\docker-compose.exe';
            $candidates[] = $programFiles.'\\Docker\\cli-plugins\\docker-compose.exe';
            $candidates[] = $programFilesX86.'\\Docker\\Docker\\resources\\bin\\docker-compose.exe';
        } else {
            $candidates[] = '/usr/bin/docker-compose';
            $candidates[] = '/usr/local/bin/docker-compose';
            $candidates[] = '/snap/bin/docker-compose';
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @return array<int, string>
     */
    private function dockerBinaryCandidates(string $configured): array
    {
        $candidates = [$configured];

        if (PHP_OS_FAMILY === 'Windows') {
            $programFiles = getenv('ProgramFiles') ?: 'C:\\Program Files';
            $programFilesX86 = getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)';
            $candidates[] = $programFiles.'\\Docker\\Docker\\resources\\bin\\docker.exe';
            $candidates[] = $programFilesX86.'\\Docker\\Docker\\resources\\bin\\docker.exe';
        } else {
            $candidates[] = '/usr/bin/docker';
            $candidates[] = '/usr/local/bin/docker';
            $candidates[] = '/snap/bin/docker';
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
