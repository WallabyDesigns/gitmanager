<?php

namespace App\Livewire\Projects;

use App\Models\DeploymentQueueItem;
use App\Services\DeploymentQueueService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Queue extends Component
{
    public function render()
    {
        $userId = Auth::id();

        $items = DeploymentQueueItem::query()
            ->with(['project'])
            ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
            ->orderBy('status')
            ->orderBy('position')
            ->get();

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
