<?php

namespace App\Livewire\Projects;

use App\Models\DeploymentQueueItem;
use App\Services\DeploymentQueueService;
use App\Services\SchedulerService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Scheduler extends Component
{
    public string $projectsTab = 'scheduler';

    public function processQueue(DeploymentQueueService $queue, SchedulerService $scheduler): void
    {
        $processed = $queue->processNext(3);
        $scheduler->recordManualRun();
        $scheduler->recordHeartbeat('manual');

        if ($processed === 0) {
            $this->dispatch('notify', message: 'No queued deployments to process.');
            $this->dispatch('$refresh');
            return;
        }

        $this->dispatch('notify', message: "Processed {$processed} queued deployment(s).");
        $this->dispatch('$refresh');
    }

    public function installCron(SchedulerService $scheduler): void
    {
        $result = $scheduler->installCron();
        $message = $result['message'] ?? 'Cron action completed.';
        $this->dispatch('notify', message: $message);
        $this->dispatch('$refresh');
    }

    public function runScheduler(SchedulerService $scheduler): void
    {
        $result = $scheduler->runScheduleNow();
        $scheduler->recordManualRun();
        $scheduler->recordHeartbeat('manual');

        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function refreshStatus(): void
    {
        $this->dispatch('$refresh');
    }

    public function render(SchedulerService $scheduler)
    {
        $userId = Auth::id();
        $queueCount = DeploymentQueueItem::query()
            ->where('status', 'queued')
            ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
            ->count();

        return view('livewire.projects.scheduler', [
            'schedulerHealthy' => $scheduler->isHealthy(),
            'lastHeartbeat' => $scheduler->lastHeartbeat(),
            'lastManualRun' => $scheduler->lastManualRun(),
            'lastSource' => $scheduler->lastSource(),
            'queueEnabled' => config('gitmanager.deploy_queue.enabled', true),
            'queueCount' => $queueCount,
            'cronCommand' => $scheduler->cronCommand(),
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.scheduler-header'),
        ]);
    }
}
