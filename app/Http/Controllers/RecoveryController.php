<?php

namespace App\Http\Controllers;

use App\Services\EnvBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class RecoveryController extends Controller
{
    public function index(Request $request, EnvBackupService $envBackup)
    {
        $log = $this->readLogTail($this->logPath());

        return view('recovery', [
            'log' => $log,
            'status' => session('repair_status'),
            'envBackups' => $envBackup->list(),
            'envStatus' => session('env_backup_status'),
        ]);
    }

    public function createEnvBackup(Request $request, EnvBackupService $envBackup)
    {
        try {
            $envBackup->backup('manual');
            $message = 'Environment backup created successfully.';
        } catch (\Throwable $e) {
            $message = 'Backup failed: '.$e->getMessage();
        }

        return redirect()->route('recovery.index')->with('env_backup_status', $message);
    }

    public function restoreEnvBackup(Request $request, EnvBackupService $envBackup, string $filename)
    {
        try {
            $envBackup->restore($filename);
            $message = "Environment restored from backup: {$filename}";
        } catch (\Throwable $e) {
            $message = 'Restore failed: '.$e->getMessage();
        }

        return redirect()->route('recovery.index')->with('env_backup_status', $message);
    }

    public function deleteEnvBackup(Request $request, EnvBackupService $envBackup, string $filename)
    {
        try {
            $envBackup->delete($filename);
            $message = "Backup deleted: {$filename}";
        } catch (\Throwable $e) {
            $message = 'Delete failed: '.$e->getMessage();
        }

        return redirect()->route('recovery.index')->with('env_backup_status', $message);
    }

    public function rebuild(Request $request)
    {
        $result = $this->runRepair();

        return redirect()
            ->route('recovery.index')
            ->with('repair_status', $result['message']);
    }

    /**
     * @return array{message: string, status: string}
     */
    private function runRepair(): array
    {
        $logPath = $this->logPath();

        $this->appendLog($logPath, '=== Asset repair started at '.now()->format('Y-m-d H:i:s').' ===');

        try {
            Artisan::call('optimize:clear');
            $this->appendLog($logPath, trim(Artisan::output()));

            Artisan::call('vendor:publish', [
                '--tag' => 'laravel-assets',
                '--force' => true,
            ]);
            $this->appendLog($logPath, trim(Artisan::output()));
        } catch (\Throwable $exception) {
            $message = 'Asset repair failed: '.$exception->getMessage();
            $this->appendLog($logPath, $message);

            return ['message' => $message, 'status' => 'failed'];
        }

        $message = 'Asset repair complete. Published assets and Laravel caches have been refreshed.';
        $this->appendLog($logPath, $message);

        return ['message' => $message, 'status' => 'success'];
    }

    private function logPath(): string
    {
        return storage_path('logs/gwm-rebuild.log');
    }

    private function appendLog(string $path, string $line): void
    {
        File::append($path, $line.PHP_EOL);
    }

    private function readLogTail(string $path, int $maxLines = 200): string
    {
        if (! is_file($path)) {
            return '';
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            return '';
        }

        $slice = array_slice($lines, -$maxLines);

        return implode("\n", $slice);
    }
}
