<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class RecoveryController extends Controller
{
    public function index(Request $request)
    {
        $log = $this->readLogTail($this->logPath());

        return view('recovery', [
            'log' => $log,
            'status' => session('rebuild_status'),
        ]);
    }

    public function rebuild(Request $request)
    {
        $result = $this->runRebuild();

        return redirect()
            ->route('recovery.index')
            ->with('rebuild_status', $result['message']);
    }

    /**
     * @return array{message: string, status: string}
     */
    private function runRebuild(): array
    {
        $root = base_path();
        $logPath = $this->logPath();
        $output = [];

        $this->appendLog($logPath, '=== Rebuild started at '.now()->format('Y-m-d H:i:s').' ===');

        if (! is_file($root.DIRECTORY_SEPARATOR.'package.json')) {
            $message = 'package.json not found. Skipping npm rebuild.';
            $this->appendLog($logPath, $message);
            return ['message' => $message, 'status' => 'failed'];
        }

        $nodeModules = $root.DIRECTORY_SEPARATOR.'node_modules';
        $buildPath = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build';

        if (is_dir($nodeModules)) {
            File::deleteDirectory($nodeModules);
            $this->appendLog($logPath, 'Removed node_modules.');
        }

        if (is_dir($buildPath)) {
            File::deleteDirectory($buildPath);
            $this->appendLog($logPath, 'Removed public/build.');
        }

        $npm = trim((string) config('gitmanager.npm_binary', 'npm'));
        if ($npm === '') {
            $npm = 'npm';
        }

        $installCommand = [ $npm, is_file($root.DIRECTORY_SEPARATOR.'package-lock.json') ? 'ci' : 'install' ];
        $env = $this->buildProcessEnv($npm);

        $commands = [
            $installCommand,
            [$npm, 'run', 'build'],
        ];

        try {
            foreach ($commands as $command) {
                $this->appendLog($logPath, '$ '.implode(' ', $command));
                $process = new Process($command, $root, $env);
                $process->setTimeout(1200);
                $process->run(function ($type, $buffer) use (&$output, $logPath) {
                    $line = rtrim($buffer);
                    if ($line === '') {
                        return;
                    }
                    $output[] = $line;
                    $this->appendLog($logPath, $line);
                });

                if (! $process->isSuccessful()) {
                    $message = 'Rebuild failed. Check the recovery log for details.';
                    $this->appendLog($logPath, $message);
                    return ['message' => $message, 'status' => 'failed'];
                }
            }
        } catch (\Throwable $exception) {
            $message = 'Rebuild failed: '.$exception->getMessage();
            $this->appendLog($logPath, $message);
            return ['message' => $message, 'status' => 'failed'];
        }

        $message = 'Rebuild complete. Assets have been reinstalled.';
        $this->appendLog($logPath, $message);
        return ['message' => $message, 'status' => 'success'];
    }

    private function logPath(): string
    {
        return storage_path('logs/gwm-rebuild.log');
    }

    /**
     * @return array<string, string>
     */
    private function buildProcessEnv(string $npmBinary): array
    {
        $env = getenv();
        $env = is_array($env) ? $env : [];

        $pathKey = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
        $currentPath = $env[$pathKey] ?? '';

        $prepend = [];
        $extraPath = trim((string) config('gitmanager.process_path', ''));
        $extraPath = trim($extraPath, "\"' ");
        if ($extraPath !== '') {
            $prepend[] = $extraPath;
        }

        $npmBinary = trim($npmBinary);
        if ($npmBinary !== '' && str_contains($npmBinary, DIRECTORY_SEPARATOR)) {
            $npmDir = dirname($npmBinary);
            if ($npmDir !== '' && $npmDir !== '.' && ! str_contains($currentPath, $npmDir)) {
                $prepend[] = $npmDir;
            }
        }

        if ($prepend) {
            $env[$pathKey] = implode(PATH_SEPARATOR, $prepend).PATH_SEPARATOR.$currentPath;
        }

        return $env;
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
