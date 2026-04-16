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
            ->where('status', 'queued')
            ->max('position');

        $item = DeploymentQueueItem::create([
            'project_id' => $project->id,
            'queued_by' => $user?->id,
            'action' => $action,
            'payload' => $payload,
            'status' => 'queued',
            'position' => $position + 1,
        ]);

        $this->normalizeQueuedPositions();

        return $item;
    }

    public function processNext(int $limit = 3): int
    {
        $this->normalizeQueuedPositions();
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

    public function processItem(DeploymentQueueItem $item): bool
    {
        $this->normalizeQueuedPositions();

        $reserved = DB::transaction(function () use ($item) {
            $fresh = DeploymentQueueItem::query()
                ->lockForUpdate()
                ->find($item->id);

            if (! $fresh || $fresh->status !== 'queued') {
                return null;
            }

            $runningProjects = DeploymentQueueItem::query()
                ->where('status', 'running')
                ->pluck('project_id')
                ->all();

            if (in_array($fresh->project_id, $runningProjects, true)) {
                return null;
            }

            $fresh->status = 'running';
            $fresh->started_at = now();
            $fresh->save();

            $this->cancelDuplicateQueuedItems($fresh);

            return $fresh;
        });

        if (! $reserved) {
            return false;
        }

        $this->runItem($reserved);
        $this->normalizeQueuedPositions();

        return true;
    }

    public function normalizeQueuedPositions(): void
    {
        $total = DeploymentQueueItem::query()
            ->where('status', 'queued')
            ->count();

        if ($total < 2) {
            return;
        }

        $distinct = DeploymentQueueItem::query()
            ->where('status', 'queued')
            ->distinct()
            ->count('position');

        if ($distinct === $total) {
            return;
        }

        $items = DeploymentQueueItem::query()
            ->where('status', 'queued')
            ->orderBy('position')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $position = 1;
        foreach ($items as $item) {
            if ($item->position !== $position) {
                $item->position = $position;
                $item->save();
            }
            $position++;
        }
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

        if ($items->isEmpty()) {
            return 0;
        }

        $projectIds = $items->pluck('project_id')->unique()->all();
        $projectsWithRunning = Deployment::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', 'running')
            ->pluck('project_id')
            ->flip()
            ->all();

        $released = 0;
        foreach ($items as $item) {
            if ($item->deployment && $item->deployment->status !== 'running') {
                $item->status = $item->deployment->status === 'success' ? 'completed' : 'failed';
                $item->finished_at = $item->deployment->finished_at ?? now();
                $item->save();
                $released++;
                continue;
            }

            if (isset($projectsWithRunning[$item->project_id])) {
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
                ->whereHas('project', function ($query) {
                    $query->where(function ($query) {
                        $query->where('permissions_locked', false)
                            ->orWhere('ftp_enabled', true)
                            ->orWhere('ssh_enabled', true);
                    });
                })
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
        $markFailed = false;

        try {
            if (! $project) {
                throw new \RuntimeException('Project not found for queued deployment.');
            }

            $payload = is_array($item->payload) ? $item->payload : [];
            switch ($item->action) {
                case 'force_deploy':
                    $deployment = $service->deploy($project, $user, true);
                    $markFailed = $deployment->status !== 'success';
                    break;
                case 'rollback':
                    $deployment = $service->rollback($project, $user, $payload['target'] ?? null);
                    $markFailed = $deployment->status !== 'success';
                    break;
                case 'audit_project':
                    $audit = $this->runAuditItem($project, $user, $payload);
                    $deployment = $audit['deployment'];
                    $markFailed = $audit['failed'];
                    break;
                case 'deploy':
                default:
                    $deployment = $service->deploy($project, $user, false);
                    $markFailed = $deployment->status !== 'success';
                    break;
            }

            if ($deployment) {
                $item->deployment_id = $deployment->id;
            }

            $item->status = $markFailed ? 'failed' : 'completed';
        } catch (\Throwable $exception) {
            $item->status = 'failed';
        }

        $item->finished_at = now();
        $item->save();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{deployment: ?Deployment, failed: bool}
     */
    private function runAuditItem(Project $project, ?User $user, array $payload): array
    {
        $autoFix = (bool) ($payload['auto_fix'] ?? true);
        $sendEmail = (bool) ($payload['send_email'] ?? true);
        $auditPayload = app(AuditService::class)->auditProject($project, $user, $autoFix, $sendEmail);
        $results = $auditPayload['results'] ?? [];

        $failed = false;
        $deploymentIds = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            if (($result['status'] ?? '') === 'failed') {
                $failed = true;
            }

            $ids = $result['deployment_ids'] ?? null;
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if (is_numeric($id)) {
                        $deploymentIds[] = (int) $id;
                    }
                }
            }
        }

        $deployment = null;
        if ($deploymentIds !== []) {
            $deployment = Deployment::query()
                ->whereIn('id', array_values(array_unique($deploymentIds)))
                ->orderByDesc('id')
                ->first();
        }

        return [
            'deployment' => $deployment,
            'failed' => $failed,
        ];
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
