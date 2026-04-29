<?php

namespace App\Services;

use App\Models\DeploymentQueueItem;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class DeploymentQueueService
{
    public function isIdle(): bool
    {
        $this->releaseStaleRunning();
        app(DeploymentService::class)->releaseStaleRunningDeployments();

        $hasQueuedOrRunningItems = DeploymentQueueItem::query()
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($hasQueuedOrRunningItems) {
            return false;
        }

        return ! Deployment::query()
            ->where('status', 'running')
            ->exists();
    }

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

    /**
     * @return array{item: DeploymentQueueItem, started: bool, existing: bool}
     */
    public function enqueueForImmediateProcessing(Project $project, string $action, array $payload = [], ?User $user = null): array
    {
        $wasIdle = $this->isIdle();
        $item = $this->enqueue($project, $action, $payload, $user);
        $existing = ! $item->wasRecentlyCreated;
        $started = false;

        if ($wasIdle && ! $existing) {
            $started = $this->startBackgroundProcessor(1);
        }

        return [
            'item' => $item,
            'started' => $started,
            'existing' => $existing,
        ];
    }

    public function processNext(?int $limit = null): int
    {
        $this->normalizeQueuedPositions();
        $processed = 0;
        $unlimited = $limit === null || $limit <= 0;

        while ($unlimited || $processed < $limit) {
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

    public function startBackgroundProcessor(int $limit = 1): bool
    {
        $limit = max(1, $limit);

        if (app()->runningUnitTests()) {
            return true;
        }

        try {
            $php = $this->phpBinary();
            $artisan = base_path('artisan');
            $logPath = $this->backgroundProcessorLogPath();
            $env = $this->backgroundProcessorEnv();
            $this->ensureBackgroundLogDirectory($logPath);

            if (PHP_OS_FAMILY === 'Windows') {
                $command = 'start "" /B '
                    .$this->cmdQuote($php).' '
                    .$this->cmdQuote($artisan).' '
                    .'deployments:process-queue '
                    .'--limit='.$this->cmdQuote((string) $limit).' '
                    .'>> '.$this->cmdQuote($logPath).' 2>&1';

                $process = Process::fromShellCommandline($command, base_path(), $env);
            } else {
                $command = 'cd '.escapeshellarg(base_path())
                    .' && nohup '.escapeshellarg($php).' '.escapeshellarg($artisan)
                    .' deployments:process-queue --limit='.escapeshellarg((string) $limit)
                    .' >> '.escapeshellarg($logPath).' 2>&1'
                    .' & echo $!';

                $process = Process::fromShellCommandline($command, base_path(), $env);
            }

            $process->setTimeout(15);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->appendBackgroundProcessorLog('Failed to launch queue processor: '.trim($process->getErrorOutput() ?: $process->getOutput()));
                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            $this->appendBackgroundProcessorLog('Failed to launch queue processor: '.$exception->getMessage());
            return false;
        }
    }

    public function normalizeQueuedPositions(): void
    {
        $items = DeploymentQueueItem::query()
            ->where('status', 'queued')
            ->orderBy('position')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($items->count() < 2) {
            return;
        }

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
        $grace = $graceSeconds ?? $this->runningGraceSeconds();
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

    private function runningGraceSeconds(): int
    {
        $configured = (int) config('gitmanager.deploy_queue.stale_seconds', 900);
        $processTimeout = (int) config('gitmanager.deployments.process_timeout', config('gitmanager.process_timeout', 900));

        if ($processTimeout <= 0) {
            return $configured;
        }

        return max($configured, $processTimeout + 300);
    }

    private function phpBinary(): string
    {
        $configured = trim((string) config('gitmanager.php_binary', 'php'));
        $configured = trim($configured, "\"' ");

        if ($configured !== '' && $configured !== 'php') {
            return $configured;
        }

        foreach ($this->phpCliCandidates($configured) as $candidate) {
            if ($this->isUsablePhpCliBinary($candidate)) {
                return $candidate;
            }
        }

        return $configured !== '' ? $configured : 'php';
    }

    /**
     * @return array<int, string>
     */
    private function phpCliCandidates(string $configured): array
    {
        $candidates = [];

        if ($configured !== '') {
            $candidates[] = $configured;
        }

        if (PHP_BINARY !== '') {
            $candidates[] = PHP_BINARY;

            $fpmMapped = str_replace(['/php-fpm83/', '/php-fpm82/', '/php-fpm81/'], ['/php83/', '/php82/', '/php81/'], PHP_BINARY);
            $fpmMapped = preg_replace('#/sbin/php-fpm(?:[0-9.]*)?$#', '/bin/php', $fpmMapped) ?: $fpmMapped;
            if ($fpmMapped !== PHP_BINARY) {
                $candidates[] = $fpmMapped;
            }
        }

        $candidates[] = '/opt/alt/php83/usr/bin/php';
        $candidates[] = '/opt/alt/php82/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        $candidates[] = 'php';

        return array_values(array_unique(array_filter($candidates)));
    }

    private function isUsablePhpCliBinary(string $binary): bool
    {
        $name = strtolower(basename($binary));
        if (str_contains($name, 'php-fpm') || str_contains($name, 'php-cgi')) {
            return false;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) && (! is_file($binary) || ! is_executable($binary))) {
            return false;
        }

        return true;
    }

    private function cmdQuote(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * @return array<string, string>
     */
    private function backgroundProcessorEnv(): array
    {
        $env = getenv();
        $env = is_array($env) ? $env : [];

        $tempPath = $this->backgroundProcessorTempPath();
        $env['TMP'] = $tempPath;
        $env['TEMP'] = $tempPath;
        $env['TMPDIR'] = $tempPath;

        return $env;
    }

    private function backgroundProcessorTempPath(): string
    {
        $path = storage_path('framework/processes');
        if (! is_dir($path)) {
            File::makeDirectory($path, 0775, true, true);
        }

        return $path;
    }

    private function backgroundProcessorLogPath(): string
    {
        return storage_path('logs/deployment-queue-worker.log');
    }

    private function ensureBackgroundLogDirectory(string $logPath): void
    {
        $directory = dirname($logPath);
        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0775, true, true);
        }
    }

    private function appendBackgroundProcessorLog(string $message): void
    {
        try {
            $logPath = $this->backgroundProcessorLogPath();
            $this->ensureBackgroundLogDirectory($logPath);
            file_put_contents($logPath, '['.now()->toDateTimeString().'] '.$message.PHP_EOL, FILE_APPEND);
        } catch (\Throwable) {
            // Best-effort diagnostics only.
        }
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
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
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
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= $maxAttempts || ! $this->isSqliteLockException($e)) {
                    throw $e;
                }
                usleep(200000 * $attempt); // 200ms, 400ms, 600ms, 800ms
            }
        }

        return null;
    }

    private function isSqliteLockException(\Throwable $e): bool
    {
        $message = $e->getMessage();
        if (str_contains($message, 'database is locked') || str_contains($message, 'SQLITE_BUSY')) {
            return true;
        }

        $previous = $e->getPrevious();
        return $previous !== null
            && (str_contains($previous->getMessage(), 'database is locked') || str_contains($previous->getMessage(), 'SQLITE_BUSY'));
    }

    private function runItem(DeploymentQueueItem $item): void
    {
        $this->applyItemRuntimeBudget();

        $service = app(DeploymentService::class);
        $project = $item->project?->fresh();
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
                case 'dependency_update':
                    $deployment = $service->updateDependencies($project, $user);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'composer_install':
                    $deployment = $service->composerInstall($project, $user);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'composer_update':
                    $deployment = $service->composerUpdate($project, $user);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'composer_audit':
                    $deployment = $service->composerAudit($project, $user);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'npm_install':
                    $deployment = $service->npmInstall($project, $user);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'npm_update':
                    $deployment = $service->npmUpdate($project, $user);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'npm_audit':
                    $deployment = $service->npmAudit($project, $user);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'npm_audit_fix':
                    $deployment = $service->npmAuditFix($project, $user, false);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'npm_audit_fix_force':
                    $deployment = $service->npmAuditFix($project, $user, true);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'app_clear_cache':
                    $deployment = $service->appClearCache($project, $user);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'laravel_migrate':
                    $deployment = $service->laravelMigrate($project, $user);
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'custom_command':
                    $deployment = $service->runCustomCommand($project, $user, (string) ($payload['command'] ?? ''));
                    $markFailed = $deployment->status === 'failed';
                    break;
                case 'deploy':
                default:
                    $deployment = $service->deploy(
                        $project,
                        $user,
                        false,
                        (bool) ($payload['ignore_permissions_lock'] ?? false)
                    );
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

        // Flush the sidebar badge cache so counts update immediately.
        $userId = $item->project?->user_id;
        if ($userId) {
            app(\App\Services\NavigationStateService::class)->flushProjectsSidebar($userId);
        }

        // Auto-advance: if more items are waiting, kick off the next processor.
        if (DeploymentQueueItem::query()->where('status', 'queued')->exists()) {
            $this->startBackgroundProcessor(1);
        }
    }

    private function applyItemRuntimeBudget(): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        $processTimeout = (int) config('gitmanager.deployments.process_timeout', config('gitmanager.process_timeout', 900));
        if ($processTimeout <= 0) {
            @set_time_limit(0);
            return;
        }

        @set_time_limit($processTimeout + 300);
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
