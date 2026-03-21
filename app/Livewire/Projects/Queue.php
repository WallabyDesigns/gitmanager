<?php

namespace App\Livewire\Projects;

use App\Models\DeploymentQueueItem;
use App\Services\DeploymentQueueService;
use App\Services\SchedulerService;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;
use Livewire\Component;

class Queue extends Component
{
    use WithPagination;

    public string $projectsTab = 'queue';
    public int $perPage = 25;
    public string $statusFilter = 'all';
    public string $actionFilter = 'all';
    public string $search = '';
    protected string $paginationTheme = 'tailwind';

    public function processNow(DeploymentQueueService $queue, SchedulerService $scheduler): void
    {
        $processed = $queue->processNext(3);
        $scheduler->recordManualRun();

        if ($processed === 0) {
            $this->dispatch('notify', message: 'No queued deployments to process.');
            return;
        }

        $this->dispatch('notify', message: "Processed {$processed} queued deployment(s).");
    }

    public function purgeDuplicates(DeploymentQueueService $queue): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $cancelled = $queue->purgeDuplicatesForUser($user);
        if ($cancelled === 0) {
            $this->dispatch('notify', message: 'No duplicate queued deployments found.');
            return;
        }

        $this->dispatch('notify', message: "Cancelled {$cancelled} duplicate queued deployment(s).");
    }

    public function clearQueue(DeploymentQueueService $queue): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $result = $queue->clearQueueForUser($user);
        $cancelled = $result['cancelled'] ?? 0;
        $deleted = $result['deleted'] ?? 0;
        if ($cancelled === 0 && $deleted === 0) {
            $this->dispatch('notify', message: 'Queue already empty.');
            return;
        }

        $this->dispatch('notify', message: "Cleared {$cancelled} queued deployment(s) and removed {$deleted} cancelled item(s).");
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->statusFilter = 'all';
        $this->actionFilter = 'all';
        $this->search = '';
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingActionFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $userId = Auth::id();

        $items = DeploymentQueueItem::query()
            ->with(['project'])
            ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->actionFilter !== 'all', fn ($query) => $query->where('action', $this->actionFilter))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('action', 'like', $term)
                        ->orWhereHas('project', fn ($project) => $project->where('name', 'like', $term));
                });
            })
            ->orderBy('status')
            ->orderBy('position')
            ->paginate($this->perPage);

        return view('livewire.projects.queue', [
            'items' => $items,
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.queue-header'),
        ]);
    }

    public function cancel(int $id, DeploymentQueueService $queue): void
    {
        $item = DeploymentQueueItem::findOrFail($id);
        abort_unless($item->project && $item->project->user_id === Auth::id(), 403);
        $queue->cancel($item);
        $this->dispatch('notify', message: 'Queued deployment cancelled.');
        $this->resetPage();
    }

    public function moveUp(int $id, DeploymentQueueService $queue): void
    {
        $item = DeploymentQueueItem::findOrFail($id);
        abort_unless($item->project && $item->project->user_id === Auth::id(), 403);
        $queue->moveUp($item);
    }

    public function moveDown(int $id, DeploymentQueueService $queue): void
    {
        $item = DeploymentQueueItem::findOrFail($id);
        abort_unless($item->project && $item->project->user_id === Auth::id(), 403);
        $queue->moveDown($item);
    }
}
