<?php

namespace App\Livewire\Processes;

use App\Models\Deployment;
use App\Models\NodeProcess;
use App\Services\DeploymentQueueService;
use App\Services\NodeProcessService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Poll;
use Livewire\Component;

class Index extends Component
{
    private const DEPLOY_ACTION_LABELS = [
        'deploy'       => 'Deploy',
        'health_check' => 'Health Check',
        'rollback'     => 'Rollback',
        'audit'        => 'Audit',
    ];

    /** @var Collection<int, Deployment> */
    public Collection $runningDeployments;

    /** @var Collection<int, NodeProcess> */
    public Collection $activeNodeProcesses;

    public int $totalActive = 0;

    public function mount(): void
    {
        $this->loadProcesses();
    }

    #[Poll(2500)]
    public function refreshProcesses(): void
    {
        $this->loadProcesses();
    }

    public function failDeployment(int $id): void
    {
        $deployment = Deployment::find($id);
        if (! $deployment || $deployment->status !== 'running') {
            return;
        }

        if ($deployment->pid) {
            $this->killProcess((int) $deployment->pid);
        }

        $deployment->status = 'failed';
        $deployment->finished_at = now();
        $deployment->save();

        $this->dispatch('notify', message: $deployment->pid
            ? 'Process signalled and marked as failed.'
            : 'Deployment marked as failed (no PID — background process may still be running).'
        );

        $this->loadProcesses();
    }

    public function stopNodeProcess(int $id, NodeProcessService $service): void
    {
        $process = NodeProcess::find($id);
        if ($process) {
            $service->stop($process);
        }

        $this->loadProcesses();
    }

    public function render(): View
    {
        return view('livewire.processes.index', [
            'actionLabels' => self::DEPLOY_ACTION_LABELS,
        ])->layout('layouts.app', [
            'title' => 'Processes',
            'header' => view('livewire.processes.partials.header'),
        ]);
    }

    private function loadProcesses(): void
    {
        $this->runningDeployments = Deployment::query()
            ->with('project:id,name')
            ->where('status', 'running')
            ->orderBy('started_at')
            ->get();

        $this->activeNodeProcesses = NodeProcess::query()
            ->with('project:id,name')
            ->whereIn('status', [NodeProcess::STATUS_RUNNING, NodeProcess::STATUS_STARTING, NodeProcess::STATUS_CRASHED])
            ->orderBy('last_started_at')
            ->get();

        $this->totalActive = $this->runningDeployments->count() + $this->activeNodeProcesses->count();
    }

    private function killProcess(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /PID {$pid} 2>&1");

            return;
        }

        if (! function_exists('posix_kill')) {
            return;
        }

        $comm = @file_get_contents("/proc/{$pid}/comm");
        if ($comm !== false && ! str_contains(strtolower(trim($comm)), 'php')) {
            return;
        }

        if (posix_kill($pid, 0)) {
            posix_kill($pid, SIGTERM);
        }
    }
}
