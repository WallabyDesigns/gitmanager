<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\DeploymentService;
use App\Services\DeploymentQueueService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DependencyActions extends Component
{
    public Project $project;
    public string $customCommand = '';
    public bool $hasComposer = false;
    public bool $hasNpm = false;
    public bool $hasLaravel = false;
    public bool $showPushModal = false;
    public array $pushFiles = [];
    public array $pushCommitPaths = [];
    public string $pushCommitMessage = '';
    public string $pushContext = '';
    public string $pushAuditSummary = '';
    public bool $pushHasOtherChanges = false;

    protected $listeners = [
        'env-updated' => '$refresh',
    ];

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->pushCommitMessage = $this->commitMessageForContext('audit');
    }

    public function render()
    {
        $dependencyActions = $this->dependencyActions();
        [$this->hasComposer, $this->hasNpm, $this->hasLaravel] = $this->detectProjectFeatures();

        $dependencyLogs = $this->project->deployments()
            ->whereIn('action', $dependencyActions)
            ->orderByDesc('started_at')
            ->take(10)
            ->get();

        return view('livewire.projects.dependency-actions', [
            'dependencyLogs' => $dependencyLogs,
            'latestDependencyLog' => $dependencyLogs->first(),
            'hasComposer' => $this->hasComposer,
            'hasNpm' => $this->hasNpm,
            'hasLaravel' => $this->hasLaravel,
            'permissionsLocked' => ! $this->project->ftp_enabled
                && ! $this->project->ssh_enabled
                && (bool) $this->project->permissions_locked,
        ]);
    }

    public function updateDependencies(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('dependency actions')) {
            return;
        }

        if ($this->enqueueIfEnabled('dependency_update')) {
            return;
        }

        $deployment = $service->updateDependencies($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Dependency update completed.'
            : 'Dependency update failed. Check logs below.');
    }

    public function clearLatestDependencyOutput(): void
    {
        $latest = $this->project
            ->deployments()
            ->whereIn('action', $this->dependencyActions())
            ->orderByDesc('started_at')
            ->first();

        if ($latest && $latest->output_log) {
            $latest->output_log = null;
            $latest->save();
        }

        $this->dispatch('notify', message: 'Latest output cleared.');
    }

    public function composerInstall(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('composer install')) {
            return;
        }

        if ($this->enqueueIfEnabled('composer_install')) {
            return;
        }

        $deployment = $service->composerInstall($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer install completed.'
            : 'Composer install failed. Check logs below.');
    }

    public function composerUpdate(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('composer update')) {
            return;
        }

        if ($this->enqueueIfEnabled('composer_update')) {
            return;
        }

        $deployment = $service->composerUpdate($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer update completed.'
            : 'Composer update failed. Check logs below.');

        $this->maybePromptPush($service, $deployment->status === 'success', 'Composer update', $deployment->output_log);
    }

    public function composerAudit(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('composer audit')) {
            return;
        }

        if ($this->enqueueIfEnabled('composer_audit')) {
            return;
        }

        $deployment = $service->composerAudit($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer audit completed.'
            : 'Composer audit failed. Check logs below.');
    }

    public function appClearCache(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('cache clearing')) {
            return;
        }

        if ($this->enqueueIfEnabled('app_clear_cache')) {
            return;
        }

        $deployment = $service->appClearCache($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'app:clear-cache completed.'
            : 'app:clear-cache failed. Check logs below.');
    }

    public function laravelMigrate(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('migrations')) {
            return;
        }

        if ($this->enqueueIfEnabled('laravel_migrate')) {
            return;
        }

        $deployment = $service->laravelMigrate($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Migrations completed.'
            : 'Migrations failed. Check logs below.');
    }

    public function npmInstall(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('npm install')) {
            return;
        }

        if ($this->enqueueIfEnabled('npm_install')) {
            return;
        }

        $deployment = $service->npmInstall($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm install completed.'
            : 'Npm install failed. Check logs below.');
    }

    public function npmUpdate(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('npm update')) {
            return;
        }

        if ($this->enqueueIfEnabled('npm_update')) {
            return;
        }

        $deployment = $service->npmUpdate($this->project, Auth::user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm update completed.'
            : 'Npm update failed. Check logs below.');

        $this->maybePromptPush($service, $deployment->status === 'success', 'Npm update', $deployment->output_log);
    }

    public function npmAuditFix(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('npm audit fix')) {
            return;
        }

        if ($this->enqueueIfEnabled('npm_audit_fix')) {
            return;
        }

        $deployment = $service->npmAuditFix($this->project, Auth::user(), false);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm audit fix completed.'
            : 'Npm audit fix failed. Check logs below.');

        $this->maybePromptPush($service, $deployment->status === 'success', 'Npm audit fix', $deployment->output_log);
    }

    public function npmAuditFixForce(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('npm audit fix')) {
            return;
        }

        if ($this->enqueueIfEnabled('npm_audit_fix_force')) {
            return;
        }

        $deployment = $service->npmAuditFix($this->project, Auth::user(), true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm audit fix (force) completed.'
            : 'Npm audit fix (force) failed. Check logs below.');

        $this->maybePromptPush($service, $deployment->status === 'success', 'Npm audit fix (force)', $deployment->output_log);
    }

    public function commitAuditFix(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('git pushes')) {
            return;
        }

        $fallback = $this->commitMessageForContext($this->pushContext ?: 'audit');
        $message = trim($this->pushCommitMessage) !== '' ? trim($this->pushCommitMessage) : $fallback;

        try {
            $paths = $this->pushCommitPaths ?: null;
            $result = $service->commitAndPush($this->project, $message, $paths);
            $status = $result['status'] ?? 'unknown';

            if ($status === 'clean') {
                $this->dispatch('notify', message: 'No changes detected to commit.');
            } elseif ($status === 'no-commit') {
                $this->dispatch('notify', message: 'Changes were staged, but nothing new to commit.');
            } else {
                $branch = $result['branch'] ?? 'current branch';
                $this->dispatch('notify', message: "Changes committed and pushed to {$branch}.");
            }
        } catch (\Throwable $exception) {
            $this->dispatch('notify', message: 'Push failed: '.$exception->getMessage());
        }

        $this->closePushModal();
    }

    public function closePushModal(): void
    {
        $this->showPushModal = false;
        $this->pushFiles = [];
        $this->pushCommitPaths = [];
        $this->pushContext = '';
        $this->pushAuditSummary = '';
        $this->pushHasOtherChanges = false;
        $this->pushCommitMessage = $this->commitMessageForContext('audit');
    }

    public function updatedShowPushModal(bool $value): void
    {
        if (! $value) {
            $this->pushFiles = [];
            $this->pushCommitPaths = [];
            $this->pushContext = '';
            $this->pushAuditSummary = '';
            $this->pushHasOtherChanges = false;
            $this->pushCommitMessage = $this->commitMessageForContext('audit');
        }
    }

    public function runCustomCommand(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('custom commands')) {
            return;
        }

        if ($this->enqueueIfEnabled('custom_command', ['command' => $this->customCommand])) {
            return;
        }

        $deployment = $service->runCustomCommand($this->project, Auth::user(), $this->customCommand);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Command completed.'
            : 'Command failed. Check logs below.');
    }

    private function enqueueIfEnabled(string $action, array $payload = []): bool
    {
        if (! (bool) config('gitmanager.deploy_queue.enabled', true)) {
            return false;
        }

        $item = app(DeploymentQueueService::class)->enqueue($this->project, $action, $payload + [
            'reason' => 'manual_dependency_action',
        ], Auth::user());

        $this->dispatch('notify', message: $item->wasRecentlyCreated
            ? $this->queuedMessage($action)
            : $this->queuedMessage($action, true));
        $this->dispatch('reload-page', delay: 300);

        return true;
    }

    private function queuedMessage(string $action, bool $existing = false): string
    {
        $label = match ($action) {
            'dependency_update' => 'Dependency update',
            'composer_install' => 'Composer install',
            'composer_update' => 'Composer update',
            'composer_audit' => 'Composer audit',
            'app_clear_cache' => 'app:clear-cache',
            'laravel_migrate' => 'Laravel migration',
            'npm_install' => 'Npm install',
            'npm_update' => 'Npm update',
            'npm_audit' => 'Npm audit',
            'npm_audit_fix' => 'Npm audit fix',
            'npm_audit_fix_force' => 'Npm audit fix (force)',
            'custom_command' => 'Custom command',
            default => ucfirst(str_replace('_', ' ', $action)),
        };

        return $existing ? "{$label} is already queued." : "{$label} queued.";
    }

    private function blockIfPermissionsLocked(string $context = 'deployments'): bool
    {
        if (! $this->project->permissions_locked || $this->project->ftp_enabled || $this->project->ssh_enabled) {
            return false;
        }

        $this->dispatch('notify', message: 'Permissions need fixing before running '.$context.'.');
        return true;
    }

    private function dependencyActions(): array
    {
        return [
            'dependency_update',
            'composer_install',
            'composer_update',
            'composer_audit',
            'app_clear_cache',
            'laravel_migrate',
            'npm_install',
            'npm_update',
            'npm_audit',
            'npm_audit_fix',
            'npm_audit_fix_force',
            'custom_command',
        ];
    }

    private function maybePromptPush(DeploymentService $service, bool $shouldCheck, string $context, ?string $outputLog = null): void
    {
        if (! $shouldCheck) {
            return;
        }

        $changes = $service->getWorkingTreeChanges($this->project);
        if (! ($changes['dirty'] ?? false)) {
            return;
        }

        $files = $changes['files'] ?? [];
        $dependencyFiles = $this->filterDependencyFiles($files, $context);
        if ($dependencyFiles === []) {
            $summary = $this->summarizeNpmAudit($outputLog);
            $label = trim($context) !== '' ? $context : 'Audit fix';
            $message = $summary !== ''
                ? "{$label}: {$summary}. No dependency changes to push."
                : "{$label} completed with no dependency file changes.";
            $this->dispatch('notify', message: $message);
            return;
        }

        $this->pushFiles = $dependencyFiles;
        $this->pushCommitPaths = $dependencyFiles;
        $this->pushContext = $context;
        $this->pushAuditSummary = $this->summarizeNpmAudit($outputLog);
        $this->pushCommitMessage = $this->buildCommitMessageForContext($context, $this->pushAuditSummary);
        $this->pushHasOtherChanges = count($files) > count($dependencyFiles);
        $this->showPushModal = true;
    }

    private function commitMessageForContext(string $context): string
    {
        $context = strtolower($context);

        if (str_contains($context, 'composer') && str_contains($context, 'update')) {
            return 'chore: update composer dependencies';
        }

        if (str_contains($context, 'npm') && str_contains($context, 'update')) {
            return 'chore: update npm dependencies';
        }

        if (str_contains($context, 'audit')) {
            return 'chore: apply npm audit fix';
        }

        return 'chore: update dependencies';
    }

    private function buildCommitMessageForContext(string $context, string $summary): string
    {
        $base = $this->commitMessageForContext($context);
        $summary = trim($summary);
        if ($summary === '' || ! str_contains(strtolower($context), 'audit')) {
            return $base;
        }

        $short = preg_replace('/\s+/', ' ', $summary) ?? $summary;
        if (strlen($short) > 80) {
            $short = substr($short, 0, 77).'...';
        }

        return $base.' ('.$short.')';
    }

    private function summarizeNpmAudit(?string $outputLog): string
    {
        $text = trim((string) $outputLog);
        if ($text === '') {
            return '';
        }

        $lines = array_reverse(preg_split('/\r?\n/', $text) ?: []);
        $found = null;
        $severitySummary = null;
        $fixed = null;
        $total = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($found === null && preg_match('/found\s+(\d+)\s+vulnerabilities(?:\s+\(([^)]+)\))?/i', $line, $matches)) {
                $found = (int) $matches[1];
                $severitySummary = isset($matches[2]) ? trim($matches[2]) : null;
            }

            if ($fixed === null && preg_match('/fixed\s+(\d+)\s+of\s+(\d+)\s+vulnerabilities/i', $line, $matches)) {
                $fixed = (int) $matches[1];
                $total = (int) $matches[2];
            }

            if ($found !== null && $fixed !== null) {
                break;
            }
        }

        $parts = [];
        if ($fixed !== null && $total !== null) {
            $parts[] = "fixed {$fixed}/{$total}";
        }
        if ($found !== null) {
            $parts[] = $found === 0 ? 'no remaining vulnerabilities' : "{$found} remaining";
        }
        if ($severitySummary) {
            $parts[] = $severitySummary;
        }

        $summary = $parts ? implode(', ', $parts) : '';
        if ($summary === '') {
            return '';
        }

        return "npm audit {$summary}";
    }

    /**
     * @param array<int, string> $files
     * @return array<int, string>
     */
    private function filterDependencyFiles(array $files, string $context): array
    {
        $context = strtolower($context);
        $isComposer = str_contains($context, 'composer');
        $targets = $isComposer
            ? ['composer.json', 'composer.lock']
            : ['package.json', 'package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'yarn.lock'];

        $matched = [];
        foreach ($files as $file) {
            $file = trim((string) $file);
            if ($file === '') {
                continue;
            }
            $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file);
            $base = strtolower(basename($normalized));
            if (in_array($base, $targets, true)) {
                $matched[] = $normalized;
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * @return array{0: bool, 1: bool, 2: bool}
     */
    private function detectProjectFeatures(): array
    {
        $path = trim((string) ($this->project->local_path ?? ''));
        if ($path === '' || ! is_dir($path)) {
            return [false, false, false];
        }

        $laravelRoot = $this->findLaravelRoot($path);
        $root = $laravelRoot ?? $path;

        $hasComposer = is_file($root.DIRECTORY_SEPARATOR.'composer.json');
        $hasNpm = is_file($root.DIRECTORY_SEPARATOR.'package.json');
        $hasLaravel = $laravelRoot !== null;

        return [$hasComposer, $hasNpm, $hasLaravel];
    }

    private function findLaravelRoot(string $path): ?string
    {
        $cursor = $path;

        while (true) {
            if (is_file($cursor.DIRECTORY_SEPARATOR.'artisan')) {
                return $cursor;
            }

            $parent = dirname($cursor);
            if (! $parent || $parent === $cursor) {
                break;
            }

            $cursor = $parent;
        }

        return null;
    }
}
