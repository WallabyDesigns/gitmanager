<?php

namespace App\Console\Commands;

use App\Services\RustExecutorInstanceService;
use Illuminate\Console\Command;

class ManageRustExecutorInstance extends Command
{
    protected $signature = 'rust-executor:instance {action : start, stop, or restart}';

    protected $description = 'Manage the app-managed Rust operations executor.';

    public function handle(RustExecutorInstanceService $executor): int
    {
        $action = strtolower((string) $this->argument('action'));
        $executor->markOperationRunning($action);

        $result = match ($action) {
            'start' => $executor->start(),
            'stop' => $executor->stop(),
            'restart' => $executor->restart(),
            default => ['success' => false, 'message' => 'Unsupported Rust executor action.'],
        };

        $message = (string) ($result['message'] ?? 'Rust executor action completed.');
        $executor->markOperationFinished($action, (bool) ($result['success'] ?? false), $message);
        ($result['success'] ?? false) ? $this->info($message) : $this->error($message);

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
