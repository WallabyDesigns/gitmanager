<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * Inspects the systemd unit that serves a Rust/Larust web application.
 *
 * Larust creates `larust-<APP_NAME>.service` with `xr deploy --service` or
 * `xr service:install`. Plain Rust projects can use the same convention when
 * they provision a systemd unit for their binary.
 */
class RuntimeServiceService
{
    public function supports(Project $project): bool
    {
        return in_array((string) $project->project_type, ['rust', 'larust'], true);
    }

    /** @return array{unit: string, installed: bool, running: bool, state: string, message: string, details: string} */
    public function status(Project $project): array
    {
        $unit = $this->projectUnit($project);
        $command = 'systemctl show '.escapeshellarg($unit)
            .' --property=LoadState --property=ActiveState --property=SubState --property=Result --property=MainPID --no-pager 2>&1'
            .'; printf "\\n--- systemctl status ---\\n"'
            .'; systemctl status '.escapeshellarg($unit).' --no-pager --lines=40 2>&1 || true'
            .'; printf "\\n--- recent journal ---\\n"'
            .'; journalctl -u '.escapeshellarg($unit).' --no-pager -n 80 2>&1 || true';

        try {
            $details = $project->ssh_enabled
                ? $this->runRemote($project, $command)
                : $this->runLocal($this->projectPath($project), $command);
        } catch (\Throwable $exception) {
            return [
                'unit' => $unit,
                'installed' => false,
                'running' => false,
                'state' => 'unavailable',
                'message' => 'Unable to inspect the service: '.$exception->getMessage(),
                'details' => '',
            ];
        }

        $status = $this->parseStatus($unit, $details);
        if (! $status['installed'] && $project->project_type !== 'larust') {
            $status['message'] = 'No systemd unit is installed or visible. Set GWM_SERVICE_NAME in the project .env when the unit name differs from the app name.';
        }

        return $status;
    }

    /** @return array{success: bool, message: string} */
    public function control(Project $project, string $action): array
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            return ['success' => false, 'message' => 'Unsupported service action.'];
        }

        $unit = $this->projectUnit($project);
        $command = 'if [ "$(id -u)" -eq 0 ]; then systemctl '.$action.' '.escapeshellarg($unit)
            .'; else sudo -n systemctl '.$action.' '.escapeshellarg($unit).'; fi';

        try {
            $output = $project->ssh_enabled
                ? $this->runRemote($project, $command)
                : $this->runLocal($this->projectPath($project), $command, false);

            return ['success' => true, 'message' => ucfirst($action).' requested for '.$unit.'.'.($output !== '' ? ' '.$this->lastLine($output) : '')];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => 'Could not '.$action.' '.$unit.'. '.$exception->getMessage()];
        }
    }

    public static function unitName(string $appName): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9]/', '_', $appName) ?: 'app';

        return 'larust-'.$safeName.'.service';
    }

    /** @return array{unit: string, installed: bool, running: bool, state: string, message: string, details: string} */
    public static function parseStatus(string $unit, string $details): array
    {
        $loadState = self::property($details, 'LoadState');
        $activeState = self::property($details, 'ActiveState');
        $subState = self::property($details, 'SubState');
        $result = self::property($details, 'Result');
        $installed = $loadState !== '' && $loadState !== 'not-found';
        $running = $activeState === 'active';
        $state = $running ? 'running' : ($installed ? ($activeState ?: 'stopped') : 'not_installed');

        $message = ! $installed
            ? 'No systemd unit is installed. Run `xr deploy --service` (or `xr service:install`) on the serving Linux host first.'
            : ($running
                ? 'Service is running'.($subState !== '' ? ' ('.$subState.').' : '.')
                : 'Service is '.$state.($result !== '' ? ' (result: '.$result.').' : '.'));

        return compact('unit', 'installed', 'running', 'state', 'message', 'details');
    }

    private function appName(Project $project): string
    {
        $fallback = trim((string) $project->name) ?: 'app';
        if ($project->ssh_enabled) {
            return Cache::remember('runtime-service:app-name:'.$project->id, now()->addMinutes(5), function () use ($project, $fallback): string {
                try {
                    $lines = $this->runRemoteLines($project, "if [ -f .env ]; then sed -n 's/^[[:space:]]*APP_NAME[[:space:]]*=[[:space:]]*//p' .env | head -n 1; fi");

                    return $this->cleanAppName($lines[0] ?? '', $fallback);
                } catch (\Throwable) {
                    return $fallback;
                }
            });
        }

        $path = $this->projectPath($project).DIRECTORY_SEPARATOR.'.env';
        if (! is_file($path)) {
            return $fallback;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*APP_NAME\s*=\s*(.*)$/', $line, $matches)) {
                return $this->cleanAppName($matches[1], $fallback);
            }
        }

        return $fallback;
    }

    private function projectUnit(Project $project): string
    {
        if ($project->project_type === 'larust') {
            return $this->unitName($this->appName($project));
        }

        $configured = $this->serviceName($project);
        if ($configured !== '') {
            return $configured;
        }

        return (preg_replace('/[^A-Za-z0-9@_.-]/', '_', $this->appName($project)) ?: 'app').'.service';
    }

    private function serviceName(Project $project): string
    {
        $fallback = '';
        if ($project->ssh_enabled) {
            return Cache::remember('runtime-service:unit:'.$project->id, now()->addMinutes(5), function () use ($project, $fallback): string {
                try {
                    $lines = $this->runRemoteLines($project, "if [ -f .env ]; then sed -n 's/^[[:space:]]*GWM_SERVICE_NAME[[:space:]]*=[[:space:]]*//p' .env | head -n 1; fi");

                    return $this->cleanUnitName($lines[0] ?? '');
                } catch (\Throwable) {
                    return $fallback;
                }
            });
        }

        $path = $this->projectPath($project).DIRECTORY_SEPARATOR.'.env';
        if (! is_file($path)) {
            return $fallback;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*GWM_SERVICE_NAME\s*=\s*(.*)$/', $line, $matches)) {
                return $this->cleanUnitName($matches[1]);
            }
        }

        return $fallback;
    }

    private function cleanAppName(string $value, string $fallback): string
    {
        $value = trim(trim($value), "\"'");

        return $value !== '' ? $value : $fallback;
    }

    private function cleanUnitName(string $value): string
    {
        $value = trim(trim($value), "\"'");
        if (! preg_match('/^[A-Za-z0-9@_.-]+$/', $value)) {
            return '';
        }

        return str_ends_with($value, '.service') ? $value : $value.'.service';
    }

    private function projectPath(Project $project): string
    {
        return rtrim((string) ($project->local_path ?: $project->directory_path ?: base_path()), DIRECTORY_SEPARATOR);
    }

    private function runLocal(string $path, string $command, bool $throwOnFailure = true): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException('Systemd service controls are available only on Linux hosts.');
        }

        $process = Process::fromShellCommandline($command, is_dir($path) ? $path : null);
        $process->setTimeout(30);
        $process->run();
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new \RuntimeException($output !== '' ? $output : 'systemctl returned exit code '.$process->getExitCode().'.');
        }

        return $output;
    }

    private function runRemote(Project $project, string $command): string
    {
        return implode("\n", $this->runRemoteLines($project, $command));
    }

    /** @return array<int, string> */
    private function runRemoteLines(Project $project, string $command): array
    {
        $project->loadMissing('ftpAccount');
        if (! $project->ftpAccount) {
            throw new \RuntimeException('SSH service inspection requires a remote access record.');
        }

        $account = $project->ftpAccount;
        $host = trim((string) $account->host);
        $username = trim((string) $account->username);
        if ($host === '' || $username === '') {
            throw new \RuntimeException('Remote access is missing a host or username.');
        }

        $root = trim((string) ($project->ssh_root_path ?: $project->ftp_root_path ?: $account->root_path));
        $output = [];

        return app(SshService::class)->runCommand(
            $host,
            max(1, (int) ($project->ssh_port ?: $account->ssh_port ?: 22)),
            $username,
            ($password = $account->getDecryptedPassword()) !== '' ? $password : null,
            $root !== '' ? $root : null,
            $command,
            $output,
            $account->ssh_pass_binary ?: null,
            $account->ssh_key_path ?: null,
        );
    }

    private static function property(string $details, string $name): string
    {
        return preg_match('/^'.preg_quote($name, '/').'=(.*)$/m', $details, $matches)
            ? trim($matches[1])
            : '';
    }

    private function lastLine(string $text): string
    {
        $lines = array_values(array_filter(preg_split('/\R/', $text) ?: [], static fn ($line) => trim($line) !== ''));

        return trim((string) end($lines));
    }
}
