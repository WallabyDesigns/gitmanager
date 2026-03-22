<?php

namespace App\Services;

use App\Models\DeploymentQueueItem;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeploymentQueueService
{
    public function enqueue(Project $project, string $action, array $payload = [], ?User $user = null): DeploymentQueueItem
    {
        $actionGroup = $this->actionGroup($action);
        $existing = DeploymentQueueItem::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ['queued', 'running'])
            ->whereIn('action', $actionGroup)
            ->orderByDesc('position')
            ->first();

        if ($existing) {
            return $existing;
        }

        $position = (int) DeploymentQueueItem::query()
            ->where('project_id', $project->id)
            ->where('status', 'queued')
            ->max('position');

        return DeploymentQueueItem::create([
            'project_id' => $project->id,
            'queued_by' => $user?->id,
            'action' => $action,
            'payload' => $payload,
            'status' => 'queued',
            'position' => $position + 1,
        ]);
    }

    public function processNext(int $limit = 3): int
    {
        $processed = 0;

        while ($processed < $limit) {
            $item = $this->reserveNext();
            if (! $item) {
                break;
            }

            $this->runItem($item);
            $processed++;
        }

        return $processed;
    }

    public function purgeDuplicatesForUser(User $user): int
    {
        $queued = DeploymentQueueItem::query()
            ->with('project')
            ->where('status', 'queued')
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->orderBy('project_id')
            ->orderBy('position')
            ->get();

        $seen = [];
        $cancelled = 0;

        foreach ($queued as $item) {
            $projectId = $item->project_id;
            $groupKey = $this->actionGroup($item->action)[0] ?? $item->action;
            $key = $projectId.'|'.$groupKey;

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                continue;
            }

            $item->status = 'cancelled';
            $item->finished_at = now();
            $item->save();
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * @return array{cancelled:int, deleted:int}
     */
    public function clearQueueForUser(User $user): array
    {
        $cancelled = DeploymentQueueItem::query()
            ->where('status', 'queued')
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->count();

        if ($cancelled > 0) {
            DeploymentQueueItem::query()
                ->where('status', 'queued')
                ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
                ->update([
                    'status' => 'cancelled',
                    'finished_at' => now(),
                ]);
        }

        $deleted = DeploymentQueueItem::query()
            ->where('status', 'cancelled')
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->delete();

        return [
            'cancelled' => $cancelled,
            'deleted' => (int) $deleted,
        ];
    }

    public function cancelQueuedGroup(Project $project, string $action): int
    {
        $group = $this->actionGroup($action);

        return DeploymentQueueItem::query()
            ->where('project_id', $project->id)
            ->where('status', 'queued')
            ->whereIn('action', $group)
            ->update([
                'status' => 'cancelled',
                'finished_at' => now(),
            ]);
    }

    public function cancel(DeploymentQueueItem $item): void
    {
        if ($item->status !== 'queued') {
            return;
        }

        $item->status = 'cancelled';
        $item->finished_at = now();
        $item->save();
    }

    public function forceCancel(DeploymentQueueItem $item): void
    {
        if (! in_array($item->status, ['queued', 'running'], true)) {
            return;
        }

        $item->status = 'cancelled';
        $item->finished_at = now();
        $item->save();
    }

    public function releaseStaleRunning(?int $graceSeconds = null): int
    {
        $grace = $graceSeconds ?? (int) config('gitmanager.deploy_queue.stale_seconds', 900);
        if ($grace <= 0) {
            return 0;
        }

        $cutoff = now()->subSeconds($grace);
        $items = DeploymentQueueItem::query()
            ->with('deployment')
            ->where('status', 'running')
            ->whereNotNull('started_at')
            ->where('started_at', '<', $cutoff)
            ->get();

        $released = 0;
        foreach ($items as $item) {
            if ($item->deployment && $item->deployment->status !== 'running') {
                $item->status = $item->deployment->status === 'success' ? 'completed' : 'failed';
                $item->finished_at = $item->deployment->finished_at ?? now();
                $item->save();
                $released++;
                continue;
            }

            $hasRunningDeployment = Deployment::query()
                ->where('project_id', $item->project_id)
                ->where('status', 'running')
                ->exists();

            if ($hasRunningDeployment) {
                continue;
            }

            $item->status = 'failed';
            $item->finished_at = now();
            $item->save();
            $released++;
        }

        return $released;
    }

    public function moveUp(DeploymentQueueItem $item): void
    {
        if ($item->status !== 'queued') {
            return;
        }

        $swap = DeploymentQueueItem::query()
            ->where('project_id', $item->project_id)
            ->where('status', 'queued')
            ->where('position', '<', $item->position)
            ->orderByDesc('position')
            ->first();

        if (! $swap) {
            return;
        }

        [$item->position, $swap->position] = [$swap->position, $item->position];
        $item->save();
        $swap->save();
    }

    public function moveDown(DeploymentQueueItem $item): void
    {
        if ($item->status !== 'queued') {
            return;
        }

        $swap = DeploymentQueueItem::query()
            ->where('project_id', $item->project_id)
            ->where('status', 'queued')
            ->where('position', '>', $item->position)
            ->orderBy('position')
            ->first();

        if (! $swap) {
            return;
        }

        [$item->position, $swap->position] = [$swap->position, $item->position];
        $item->save();
        $swap->save();
    }

    private function reserveNext(): ?DeploymentQueueItem
    {
        return DB::transaction(function () {
            $runningProjects = DeploymentQueueItem::query()
                ->where('status', 'running')
                ->pluck('project_id')
                ->all();

            $item = DeploymentQueueItem::query()
                ->where('status', 'queued')
                ->whereHas('project', fn ($query) => $query->where('permissions_locked', false))
                ->when($runningProjects !== [], fn ($query) => $query->whereNotIn('project_id', $runningProjects))
                ->orderBy('position')
                ->lockForUpdate()
                ->first();

            if (! $item) {
                return null;
            }

            $item->status = 'running';
            $item->started_at = now();
            $item->save();

            $this->cancelDuplicateQueuedItems($item);

            return $item;
        });
    }

    private function runItem(DeploymentQueueItem $item): void
    {
        $service = app(DeploymentService::class);
        $project = $item->project;
        $user = $item->queuedBy;
        $deployment = null;

        try {
            if (! $project) {
                throw new \RuntimeException('Project not found for queued deployment.');
            }

            $payload = is_array($item->payload) ? $item->payload : [];
            $deployment = match ($item->action) {
                'force_deploy' => $service->deploy($project, $user, true),
                'rollback' => $service->rollback($project, $user, $payload['target'] ?? null),
                default => $service->deploy($project, $user, false),
            };

            $item->deployment_id = $deployment->id;
            $item->status = $deployment->status === 'success' ? 'completed' : 'failed';
        } catch (\Throwable $exception) {
            $item->status = 'failed';
        }

        $item->finished_at = now();
        $item->save();
    }

    /**
     * @return array<int, string>
     */
    private function actionGroup(string $action): array
    {
        if (in_array($action, ['deploy', 'force_deploy'], true)) {
            return ['deploy', 'force_deploy'];
        }

        return [$action];
    }

    private function cancelDuplicateQueuedItems(DeploymentQueueItem $item): void
    {
        $group = $this->actionGroup($item->action);

        DeploymentQueueItem::query()
            ->where('status', 'queued')
            ->where('project_id', $item->project_id)
            ->whereIn('action', $group)
            ->where('id', '!=', $item->id)
            ->update([
                'status' => 'cancelled',
                'finished_at' => now(),
            ]);
    }
}
