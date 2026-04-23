<?php

namespace App\Livewire\Projects;

use App\Models\DeploymentQueueItem;
use App\Models\Deployment;
use App\Services\DeploymentQueueService;
use App\Services\SchedulerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\WithPagination;
use Livewire\Component;

class Queue extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const STATUS_FILTERS = ['queued', 'running', 'failed', 'completed', 'cancelled', 'all'];

    public string $projectsTab = 'queue';
    public int $perPage = 25;
    public string $statusFilter = 'queued';
    public string $actionFilter = 'all';
    public string $search = '';
    protected string $paginationTheme = 'tailwind';

    public function processNow(DeploymentQueueService $queue, SchedulerService $scheduler): void
    {
        $processed = $queue->processNext((int) config('gitmanager.deploy_queue.batch_size', 0));
        $scheduler->recordManualRun();

        if ($processed === 0) {
            $this->dispatch('notify', message: 'No queued items to process.');
            return;
        }

        $this->dispatch('notify', message: "Processed {$processed} queued item(s).");
    }

    public function processItem(int $id, DeploymentQueueService $queue, SchedulerService $scheduler): void
    {
        $item = DeploymentQueueItem::findOrFail($id);
        $this->authorize('update', $item);

        $processed = $queue->processItem($item);
        $scheduler->recordManualRun();

        if (! $processed) {
            $this->dispatch('notify', message: 'Queue item could not be processed.');
            return;
        }

        $this->dispatch('notify', message: 'Queue item processed.');
        $this->resetPage();
    }

    public function purgeDuplicates(DeploymentQueueService $queue): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $cancelled = $queue->purgeDuplicatesForUser($user);
        if ($cancelled === 0) {
            $this->dispatch('notify', message: 'No duplicate queued items found.');
            return;
        }

        $this->dispatch('notify', message: "Cancelled {$cancelled} duplicate queued item(s).");
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

        $this->dispatch('notify', message: "Cleared {$cancelled} queued item(s) and removed {$deleted} cancelled item(s).");
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->statusFilter = 'queued';
        $this->actionFilter = 'all';
        $this->search = '';
        $this->resetPage();
    }

    public function setStatusFilter(string $status): void
    {
        if (! in_array($status, self::STATUS_FILTERS, true)) {
            return;
        }

        $this->statusFilter = $status;
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
        $queueService = app(DeploymentQueueService::class);
        $queueService->releaseStaleRunning();
        $queueService->normalizeQueuedPositions();
        $logLimit = $this->logPreviewLimit();
        $logPreview = DB::raw($this->logPreviewSql('output_log', $logLimit).' as output_log');
        $deploymentColumns = $this->deploymentColumns();

        $items = DeploymentQueueItem::query()
            ->with([
                'project',
                'deployment' => function ($query) use ($deploymentColumns, $logPreview) {
                    $query->select($deploymentColumns)->addSelect($logPreview);
                },
            ])
            ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->actionFilter !== 'all', fn ($query) => $query->where('action', $this->actionFilter))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('action', 'like', $term)
                        ->orWhereHas('project', function ($project) use ($term) {
                            $project->where('name', 'like', $term)
                                ->orWhere('site_url', 'like', $term)
                                ->orWhere('repo_url', 'like', $term);
                        });
                });
            })
            ->orderByDesc(DB::raw('COALESCE(finished_at, started_at, created_at)'))
            ->orderByDesc('id')
            ->paginate($this->perPage);

        $projectIds = $items->getCollection()
            ->pluck('project_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $runningDeployments = $projectIds
            ? Deployment::query()
                ->select($deploymentColumns)
                ->addSelect($logPreview)
                ->whereIn('project_id', $projectIds)
                ->where('status', 'running')
                ->latest('started_at')
                ->get()
                ->keyBy('project_id')
            : collect();

        return view('livewire.projects.queue', [
            'items' => $items,
            'runningDeployments' => $runningDeployments,
            'staleSeconds' => (int) config('gitmanager.deploy_queue.stale_seconds', 900),
        ])->layout('layouts.app', [
            'title' => 'Task Queue',
            'header' => view('livewire.projects.partials.queue-header'),
        ]);
    }

    public function cancel(int $id, DeploymentQueueService $queue): void
    {
        $item = DeploymentQueueItem::findOrFail($id);
        $this->authorize('update', $item);
        $queue->cancel($item);
        $this->dispatch('notify', message: 'Queued item cancelled.');
        $this->resetPage();
    }

    public function moveUp(int $id, DeploymentQueueService $queue): void
    {
        $item = DeploymentQueueItem::findOrFail($id);
        $this->authorize('update', $item);
        $queue->moveUp($item);
    }

    public function moveDown(int $id, DeploymentQueueService $queue): void
    {
        $item = DeploymentQueueItem::findOrFail($id);
        $this->authorize('update', $item);
        $queue->moveDown($item);
    }

    public function forceCancel(int $id, DeploymentQueueService $queue): void
    {
        $item = DeploymentQueueItem::findOrFail($id);
        $this->authorize('update', $item);
        $queue->forceCancel($item);
        $this->dispatch('notify', message: 'Queue item cancelled.');
        $this->resetPage();
    }

    /**
     * @return array{label: string, context: string|null}
     */
    public function actionPresentation(DeploymentQueueItem $item): array
    {
        $payload = is_array($item->payload) ? $item->payload : [];
        $reason = trim((string) ($payload['reason'] ?? ''));
        $source = trim((string) ($payload['source'] ?? ''));

        return [
            'label' => $this->actionLabel($item->action),
            'context' => $this->actionContextLabel($item->action, $reason, $source),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function deploymentColumns(): array
    {
        return [
            'id',
            'project_id',
            'triggered_by',
            'action',
            'status',
            'from_hash',
            'to_hash',
            'started_at',
            'finished_at',
        ];
    }

    private function logPreviewLimit(): int
    {
        return 120000;
    }

    private function logPreviewSql(string $column, int $limit): string
    {
        return "CASE WHEN length({$column}) > {$limit} THEN substr({$column}, length({$column}) - {$limit} + 1) ELSE {$column} END";
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'deploy' => 'Deployment',
            'force_deploy' => 'Force Deployment',
            'rollback' => 'Rollback',
            'dependency_update' => 'Dependency Update',
            'composer_install' => 'Composer Install',
            'composer_update' => 'Composer Update',
            'composer_audit' => 'Composer Audit',
            'npm_install' => 'Npm Install',
            'npm_update' => 'Npm Update',
            'npm_audit_fix' => 'Npm Audit Fix',
            'npm_audit_fix_force' => 'Npm Audit Fix (Force)',
            'audit_project' => 'Project Audit',
            'app_clear_cache' => 'App Clear Cache',
            'laravel_migrate' => 'Laravel Migrate',
            'preview_build' => 'Preview Build',
            'custom_command' => 'Custom Command',
            default => ucfirst(str_replace('_', ' ', $action)),
        };
    }

    private function actionContextLabel(string $action, string $reason, string $source): ?string
    {
        $context = $reason !== '' ? $reason : $source;

        return match ($context) {
            'auto_update' => 'Auto Update',
            'manual_update_check' => 'Update Check',
            'project_created' => 'Project Created',
            'env_saved' => '.env Saved',
            'manual_deploy' => 'Manual Run',
            'manual_force_deploy' => 'Manual Force Run',
            'manual_staged_deploy' => 'Staged Install',
            'manual_rollback' => 'Manual Rollback',
            'manual_project_audit' => 'Manual Audit',
            'bulk_project_audit' => 'Bulk Audit',
            'scheduled_hourly_audit' => 'Scheduled Audit',
            default => $this->fallbackContextLabel($action, $context),
        };
    }

    private function fallbackContextLabel(string $action, string $context): ?string
    {
        if ($action === 'audit_project' && $context === '') {
            return 'Audit';
        }

        if ($context === '') {
            return null;
        }

        return ucfirst(str_replace('_', ' ', $context));
    }
}
