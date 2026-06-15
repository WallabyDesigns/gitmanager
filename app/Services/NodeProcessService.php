<?php

namespace App\Services;

use App\Models\NodeProcess;
use App\Models\Project;
use Illuminate\Support\Facades\File;

class NodeProcessService
{
    public const FREE_LIMIT = 3;

    public function __construct(
        private readonly NodeInstallService $nodeInstall,
        private readonly EditionService $edition,
        private readonly LicenseService $license,
    ) {}

    /**
     * Return the NodeProcess record for a project, creating it if absent.
     */
    public function forProject(Project $project): NodeProcess
    {
        return NodeProcess::firstOrCreate(
            ['project_id' => $project->id],
            ['status' => NodeProcess::STATUS_STOPPED, 'start_command' => 'npm start'],
        );
    }

    /**
     * Whether this installation allows creating/running another node process.
     * Crashed processes count against the limit so users can't bypass it.
     */
    public function canRunMore(): bool
    {
        if ($this->isEnterprise()) {
            return true;
        }

        $active = NodeProcess::query()
            ->whereIn('status', [NodeProcess::STATUS_RUNNING, NodeProcess::STATUS_STARTING, NodeProcess::STATUS_CRASHED])
            ->count();

        return $active < self::FREE_LIMIT;
    }

    public function activeCount(): int
    {
        return NodeProcess::query()
            ->whereIn('status', [NodeProcess::STATUS_RUNNING, NodeProcess::STATUS_STARTING, NodeProcess::STATUS_CRASHED])
            ->count();
    }

    public function start(NodeProcess $process): array
    {
        if (! $this->nodeInstall->isInstalled()) {
            return ['success' => false, 'message' => 'Node.js is not installed. Install it from System → Node.js.'];
        }

        if ($process->isActive()) {
            return ['success' => false, 'message' => 'Process is already running.'];
        }

        if (! $process->isRunning() && ! $this->canRunMore()) {
            return [
                'success' => false,
                'message' => 'Free accounts are limited to '.self::FREE_LIMIT.' active Node processes. Upgrade to Enterprise for unlimited.',
            ];
        }

        $project = $process->project;
        $workdir = $project->local_path ?? base_path();

        if (! is_dir($workdir)) {
            return ['success' => false, 'message' => "Project directory does not exist: {$workdir}"];
        }

        $logPath = $process->logPath();
        $this->ensureLogDirectory($logPath);

        $nodeBin = $this->nodeInstall->nodeBinary();
        $command = trim($process->start_command);

        // Write the supervisor wrapper so auto-restart survives process death
        $scriptPath = $this->writeSupervisorScript($process, $nodeBin, $command, $workdir, $logPath);
        if ($scriptPath === null) {
            return ['success' => false, 'message' => 'Could not write supervisor script.'];
        }

        $pid = $this->spawnSupervisor($scriptPath, $logPath);
        if ($pid === null) {
            return ['success' => false, 'message' => 'Failed to spawn process.'];
        }

        $process->forceFill([
            'pid' => $pid,
            'status' => NodeProcess::STATUS_RUNNING,
            'last_started_at' => now(),
            'crash_count' => 0,
        ])->save();

        return ['success' => true, 'message' => "Node process started (PID {$pid})."];
    }

    public function stop(NodeProcess $process): array
    {
        if ($process->isStopped()) {
            return ['success' => false, 'message' => 'Process is not running.'];
        }

        if ($process->pid) {
            $this->killPid((int) $process->pid);
        }

        $process->forceFill([
            'pid' => null,
            'status' => NodeProcess::STATUS_STOPPED,
            'last_stopped_at' => now(),
        ])->save();

        return ['success' => true, 'message' => 'Node process stopped.'];
    }

    public function restart(NodeProcess $process): array
    {
        $this->stop($process);
        $process->refresh();

        return $this->start($process);
    }

    /**
     * Check whether the stored PID is still alive and sync status accordingly.
     * Called by the NodeHealthCheck scheduler command.
     */
    public function syncStatus(NodeProcess $process): void
    {
        if ($process->isStopped()) {
            return;
        }

        $alive = $process->pid && $this->isPidAlive((int) $process->pid);

        if (! $alive && $process->isActive()) {
            $updates = [
                'status' => NodeProcess::STATUS_CRASHED,
                'pid' => null,
                'last_crashed_at' => now(),
                'crash_count' => $process->crash_count + 1,
            ];

            // If auto-restart is on, attempt to bring it back up
            if ($process->auto_restart) {
                $process->forceFill($updates)->save();
                $process->refresh();
                $this->start($process);

                return;
            }

            $process->forceFill($updates)->save();
        }
    }

    public function tailLog(NodeProcess $process, int $lines = 100): string
    {
        $path = $process->logPath();
        if (! is_file($path)) {
            return '';
        }

        // Read last N lines without loading the whole file into memory
        try {
            $fp = fopen($path, 'r');
            if (! $fp) {
                return '';
            }

            $buffer = [];
            fseek($fp, 0, SEEK_END);
            $pos = ftell($fp);
            $chunk = '';

            while ($pos > 0 && count($buffer) < $lines + 1) {
                $read = min(4096, $pos);
                $pos -= $read;
                fseek($fp, $pos);
                $chunk = fread($fp, $read).$chunk;
                $buffer = explode("\n", $chunk);
            }

            fclose($fp);

            return implode("\n", array_slice($buffer, -$lines));
        } catch (\Throwable) {
            return '';
        }
    }

    public function clearLog(NodeProcess $process): void
    {
        $path = $process->logPath();
        if (is_file($path)) {
            @file_put_contents($path, '');
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function writeSupervisorScript(
        NodeProcess $process,
        string $nodeBin,
        string $command,
        string $workdir,
        string $logPath,
    ): ?string {
        $scriptPath = $process->supervisorScriptPath();

        if (PHP_OS_FAMILY === 'Windows') {
            // PowerShell wrapper
            $ps = <<<POWERSHELL
            while (\$true) {
                Set-Location -Path '{$workdir}'
                & {$nodeBin} {$command} >> '{$logPath}' 2>&1
                Add-Content -Path '{$logPath}' -Value "[supervisor] Process exited, restarting in 2s..."
                Start-Sleep -Seconds 2
            }
            POWERSHELL;
            $scriptPath = str_replace('.sh', '.ps1', $scriptPath);
            if (@file_put_contents($scriptPath, $ps) === false) {
                return null;
            }
        } else {
            $nodeBinEsc = escapeshellarg($nodeBin);
            $workdirEsc = escapeshellarg($workdir);
            $logPathEsc = escapeshellarg($logPath);
            // Split start_command so each token is its own argument
            $cmdTokens = implode(' ', array_map('escapeshellarg', explode(' ', $command)));

            $sh = <<<BASH
            #!/usr/bin/env bash
            cd {$workdirEsc}
            while true; do
                {$nodeBinEsc} {$cmdTokens} >> {$logPathEsc} 2>&1
                echo "[supervisor] Process exited at \$(date), restarting in 2s..." >> {$logPathEsc}
                sleep 2
            done
            BASH;

            if (@file_put_contents($scriptPath, $sh) === false) {
                return null;
            }
            @chmod($scriptPath, 0755);
        }

        return $scriptPath;
    }

    private function spawnSupervisor(string $scriptPath, string $logPath): ?int
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $cmd = "powershell -NonInteractive -WindowStyle Hidden -File ".escapeshellarg($scriptPath);
                $wscript = 'wscript //B //NoLogo '.escapeshellarg($scriptPath);
                pclose(popen("start /B {$cmd} >> ".escapeshellarg($logPath)." 2>&1", 'r'));
                // Retrieve PID via tasklist isn't reliable here; use a pid file approach
                return $this->spawnWithPidFile($scriptPath, $logPath);
            } else {
                $escaped = escapeshellarg($scriptPath);
                $logEscaped = escapeshellarg($logPath);
                // nohup ensures it survives the parent process
                exec("nohup bash {$escaped} >> {$logEscaped} 2>&1 & echo $!", $output);
                $pid = (int) trim($output[0] ?? '0');

                return $pid > 0 ? $pid : null;
            }
        } catch (\Throwable) {
            return null;
        }
    }

    private function spawnWithPidFile(string $scriptPath, string $logPath): ?int
    {
        $pidFile = dirname($scriptPath).DIRECTORY_SEPARATOR.'process.pid';
        $logEscaped = escapeshellarg($logPath);
        $pidFileEscaped = escapeshellarg($pidFile);
        $scriptEscaped = escapeshellarg($scriptPath);

        exec("powershell -NonInteractive -WindowStyle Hidden -Command \"Start-Process powershell -ArgumentList '-NonInteractive -WindowStyle Hidden -File {$scriptEscaped}' -RedirectStandardOutput {$logEscaped} -PassThru | Select-Object -ExpandProperty Id | Out-File {$pidFileEscaped} -Encoding ASCII\"");

        if (is_file($pidFile)) {
            $pid = (int) trim(file_get_contents($pidFile) ?: '0');

            return $pid > 0 ? $pid : null;
        }

        return null;
    }

    private function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("tasklist /FI \"PID eq {$pid}\" /NH 2>NUL", $output);

                return count(array_filter($output, fn ($l) => str_contains($l, (string) $pid))) > 0;
            }

            // posix_kill with signal 0 checks existence without sending a signal
            if (function_exists('posix_kill')) {
                return posix_kill($pid, 0);
            }

            // Fallback: check /proc
            return is_dir("/proc/{$pid}");
        } catch (\Throwable) {
            return false;
        }
    }

    private function killPid(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /T /PID {$pid} 2>NUL");
            } else {
                // Kill the entire process group to take down child node processes too
                exec("kill -- -$(ps -o pgid= -p {$pid} 2>/dev/null | tr -d ' ') 2>/dev/null || kill {$pid} 2>/dev/null");
            }
        } catch (\Throwable) {
            // Best-effort kill
        }
    }

    private function ensureLogDirectory(string $logPath): void
    {
        $dir = dirname($logPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (! is_file($logPath)) {
            @touch($logPath);
        }
    }

    private function isEnterprise(): bool
    {
        return $this->edition->current() === EditionService::ENTERPRISE
            && $this->license->hasValidEnterpriseLicense();
    }
}
