<?php

namespace App\Console\Commands;

use App\Models\NodeProcess;
use App\Services\NodeProcessService;
use Illuminate\Console\Command;

class NodeHealthCheck extends Command
{
    protected $signature = 'node:health-check';

    protected $description = 'Check whether tracked Node.js processes are still alive and sync their status.';

    public function handle(NodeProcessService $service): int
    {
        $active = NodeProcess::query()
            ->whereIn('status', [NodeProcess::STATUS_RUNNING, NodeProcess::STATUS_STARTING])
            ->get();

        if ($active->isEmpty()) {
            $this->line('No active Node processes to check.');

            return self::SUCCESS;
        }

        foreach ($active as $process) {
            $before = $process->status;
            $service->syncStatus($process);
            $process->refresh();
            $after = $process->status;

            if ($before !== $after) {
                $this->warn("Project #{$process->project_id}: status changed {$before} → {$after}.");
            } else {
                $this->line("Project #{$process->project_id}: {$after} (PID {$process->pid}).");
            }
        }

        return self::SUCCESS;
    }
}
