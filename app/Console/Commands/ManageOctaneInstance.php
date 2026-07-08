<?php

namespace App\Console\Commands;

use App\Services\OctaneInstanceService;
use Illuminate\Console\Command;

class ManageOctaneInstance extends Command
{
    protected $signature = 'octane:instance
        {action : start, stop, or restart}
        {--no-build : Start without rebuilding the Octane image}';

    protected $description = 'Manage the app-managed Octane Docker Compose instance.';

    public function handle(OctaneInstanceService $octane): int
    {
        $action = strtolower((string) $this->argument('action'));

        $result = match ($action) {
            'start' => $octane->start(! $this->option('no-build')),
            'stop' => $octane->stop(),
            'restart' => $octane->restart(),
            default => ['success' => false, 'message' => 'Unsupported Octane action.'],
        };

        $message = (string) ($result['message'] ?? 'Octane action completed.');
        $result['success'] ?? false
            ? $this->info($message)
            : $this->error($message);

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
