<?php

namespace App\Livewire\Projects;

use App\Models\AuditIssue;
use App\Models\Project;
use App\Models\SecurityAlert;
use App\Services\AuditService;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use App\Services\EditionService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Livewire\Component;

class SecurityAlerts extends Component
{
    public Project $project;

    public string $tab = 'current';

    public bool $sslVerifyEnabled = true;

    public bool $hasComposer = false;

    public bool $hasNpm = false;

    public bool $showPushModal = false;

    public array $pushFiles = [];

    public array $pushCommitPaths = [];

    public string $pushCommitMessage = '';

    public string $pushContext = '';

    public string $pushAuditSummary = '';

    public bool $pushHasOtherChanges = false;

    public function mount(Project $project, SettingsService $settings): void
    {
        $this->project = $project;
        $this->sslVerifyEnabled = (bool) ($settings->get(
            'system.github_ssl_verify',
            (bool) config('services.github.verify_ssl', true)
        ));
        $this->pushCommitMessage = $this->commitMessageForContext('audit');
    }

    public function render(EditionService $edition): View
    {
        $tab = in_array($this->tab, ['current', 'resolved'], true) ? $this->tab : 'current';

        $query = SecurityAlert::query()
            ->where('project_id', $this->project->id);

        $alerts = $tab === 'resolved'
            ? $query->where('state', '!=', 'open')
            : $query->where('state', 'open');

        $auditQuery = AuditIssue::query()
            ->where('project_id', $this->project->id);

        $auditIssues = $tab === 'resolved'
            ? $auditQuery->where('status', 'resolved')
            : $auditQuery->where('status', 'open');

        $securityLogs = $this->project->deployments()
            ->whereIn('action', $this->securityActions())
            ->orderByDesc('started_at')
            ->take(6)
            ->get();

        [$this->hasComposer, $this->hasNpm] = $this->detectProjectFeatures();
        $permissionsLocked = ! $this->project->ftp_enabled
            && ! $this->project->ssh_enabled
            && (bool) $this->project->permissions_locked;

        return view('livewire.projects.security-alerts', [
            'alerts' => $alerts->orderByDesc('alert_created_at')->get(),
            'auditIssues' => $auditIssues->orderByDesc('detected_at')->get(),
            'openCount' => SecurityAlert::query()
                ->where('project_id', $this->project->id)
                ->where('state', 'open')
                ->count()
                + AuditIssue::query()
                    ->where('project_id', $this->project->id)
                    ->where('status', 'open')
                    ->count(),
            'resolvedCount' => SecurityAlert::query()
                ->where('project_id', $this->project->id)
                ->where('state', '!=', 'open')
                ->count()
                + AuditIssue::query()
                    ->where('project_id', $this->project->id)
                    ->where('status', 'resolved')
                    ->count(),
            'tab' => $tab,
            'sslVerifyEnabled' => $this->sslVerifyEnabled,
            'hasComposer' => $this->hasComposer,
            'hasNpm' => $this->hasNpm,
            'permissionsLocked' => $permissionsLocked,
            'isEnterprise' => $edition->current() === EditionService::ENTERPRISE,
            'securityLogs' => $securityLogs,
            'latestSecurityLog' => $securityLogs->first(),
        ]);
    }

    public function sync(): void
    {
        try {
            Artisan::call('security:sync');
            $this->dispatch('notify', message: 'Security alerts synced.');
        } catch (\Throwable $exception) {
            $this->dispatch('notify', message: 'Security sync failed: '.$exception->getMessage());
        }
    }

    public function auditProject(AuditService $audit, DeploymentService $deployment, EditionService $edition): void
    {
        if ($edition->current() !== EditionService::ENTERPRISE) {
            $this->dispatch('notify', message: 'Automatic project audits are available in Enterprise Edition.', type: 'warning');
            $this->dispatch('gwm-open-enterprise-modal', feature: 'Automatic Project & Container Audits');

            return;
        }

        if ($this->blockIfPermissionsLocked('audit checks')) {
            return;
        }

        if ((bool) config('gitmanager.deploy_queue.enabled', true)) {
            $result = app(DeploymentQueueService::class)->enqueueForImmediateProcessing($this->project, 'audit_project', [
                'auto_fix' => true,
                'send_email' => true,
                'source' => 'manual_project_audit',
            ], auth()->user());

            $this->dispatch('notify', message: $result['existing']
                ? 'Project audit is already queued.'
                : ($result['started'] ? 'Project audit started.' : 'Project audit queued.'));

            return;
        }

        $payload = $audit->auditProject($this->project, auth()->user(), true, true);
        $this->project->refresh();
        $this->maybePromptPushFromResults($deployment, $payload['results'] ?? []);
        $this->dispatchAuditToast($payload['results'] ?? []);
    }

    public function composerUpdate(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('composer update')) {
            return;
        }

        if ($this->enqueueIfEnabled('composer_update')) {
            return;
        }

        $deployment = $service->composerUpdate($this->project, auth()->user());
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Composer update completed.'
            : 'Composer update failed. Check logs below.');

        $this->maybePromptPush($service, $deployment->status === 'success', 'Composer update', $deployment->output_log);
    }

    public function npmAuditFix(DeploymentService $service): void
    {
        if ($this->blockIfPermissionsLocked('npm audit fix')) {
            return;
        }

        if ($this->enqueueIfEnabled('npm_audit_fix')) {
            return;
        }

        $deployment = $service->npmAuditFix($this->project, auth()->user(), false);
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

        $deployment = $service->npmAuditFix($this->project, auth()->user(), true);
        $this->project->refresh();
        $this->dispatch('notify', message: $deployment->status === 'success'
            ? 'Npm audit fix (force) completed.'
            : 'Npm audit fix (force) failed. Check logs below.');

        $this->maybePromptPush($service, $deployment->status === 'success', 'Npm audit fix (force)', $deployment->output_log);
    }

    private function enqueueIfEnabled(string $action): bool
    {
        if (! (bool) config('gitmanager.deploy_queue.enabled', true)) {
            return false;
        }

        $result = app(DeploymentQueueService::class)->enqueueForImmediateProcessing($this->project, $action, [
            'reason' => 'manual_security_action',
        ], auth()->user());

        $label = match ($action) {
            'composer_update' => 'Composer update',
            'npm_audit_fix' => 'Npm audit fix',
            'npm_audit_fix_force' => 'Npm audit fix (force)',
            default => ucfirst(str_replace('_', ' ', $action)),
        };

        $this->dispatch('notify', message: $result['existing']
            ? "{$label} is already queued."
            : ($result['started'] ? "{$label} started." : "{$label} queued."));

        return true;
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

    private function blockIfPermissionsLocked(string $context): bool
    {
        if (! $this->project->permissions_locked || $this->project->ftp_enabled || $this->project->ssh_enabled) {
            return false;
        }

        $this->dispatch('notify', message: 'Permissions need fixing before running '.$context.'.');

        return true;
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

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function maybePromptPushFromResults(DeploymentService $deployment, array $results): void
    {
        $changes = $deployment->getWorkingTreeChanges($this->project);
        if (! ($changes['dirty'] ?? false)) {
            return;
        }

        $files = $changes['files'] ?? [];
        $dependencyFiles = $this->filterDependencyFiles($files, 'audit');
        if ($dependencyFiles === []) {
            return;
        }

        $summary = $this->summarizeAuditResultsText($results);
        $this->pushFiles = $dependencyFiles;
        $this->pushCommitPaths = $dependencyFiles;
        $this->pushContext = 'Audit Project';
        $this->pushAuditSummary = $summary;
        $this->pushCommitMessage = $this->buildCommitMessageForContext('audit', $summary);
        $this->pushHasOtherChanges = count($files) > count($dependencyFiles);
        $this->showPushModal = true;
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function dispatchAuditToast(array $results): void
    {
        $summary = $this->summarizeAuditResults($results);
        $remaining = $summary['remaining'];

        if ($remaining > 0) {
            $label = $remaining === 1 ? '1 vulnerability' : "{$remaining} vulnerabilities";
            $this->dispatch('notify', message: "Vulnerabilities found ({$label} remaining). Open the Security tab to resolve them.", type: 'error');

            return;
        }

        if ($summary['failed'] > 0) {
            $this->dispatch('notify', message: 'Audit completed with errors. Check the logs for details.', type: 'warning');

            return;
        }

        $this->dispatch('notify', message: 'Audit complete. No vulnerabilities found.', type: 'success');
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @return array{remaining: int, failed: int}
     */
    private function summarizeAuditResults(array $results): array
    {
        $remaining = 0;
        $failed = 0;

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            if (($result['status'] ?? '') === 'failed') {
                $failed++;
            }

            $count = $result['remaining'] ?? null;
            if (is_numeric($count)) {
                $remaining += (int) $count;
            }
        }

        return [
            'remaining' => $remaining,
            'failed' => $failed,
        ];
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
     * @param  array<int, string>  $files
     * @return array<int, string>
     */
    private function filterDependencyFiles(array $files, string $context): array
    {
        $context = strtolower($context);
        $isComposer = str_contains($context, 'composer');
        $isNpm = str_contains($context, 'npm');
        $isAudit = str_contains($context, 'audit');

        if ($isComposer && ! $isNpm) {
            $targets = ['composer.json', 'composer.lock'];
        } elseif ($isNpm && ! $isComposer) {
            $targets = ['package.json', 'package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'yarn.lock'];
        } elseif ($isAudit) {
            $targets = [
                'composer.json',
                'composer.lock',
                'package.json',
                'package-lock.json',
                'npm-shrinkwrap.json',
                'pnpm-lock.yaml',
                'yarn.lock',
            ];
        } else {
            $targets = [
                'composer.json',
                'composer.lock',
                'package.json',
                'package-lock.json',
                'npm-shrinkwrap.json',
                'pnpm-lock.yaml',
                'yarn.lock',
            ];
        }

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
     * @param  array<string, array<string, mixed>>  $results
     */
    private function summarizeAuditResultsText(array $results): string
    {
        $summaries = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $summary = trim((string) ($result['fix_summary'] ?? $result['summary'] ?? ''));
            if ($summary !== '') {
                $summaries[] = $summary;
            }
        }

        return implode('; ', $summaries);
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function detectProjectFeatures(): array
    {
        $path = trim((string) ($this->project->local_path ?? ''));
        if ($path === '' || ! is_dir($path)) {
            return [false, false];
        }

        $laravelRoot = $this->findLaravelRoot($path);
        $root = $laravelRoot ?? $path;

        $hasComposer = is_file($root.DIRECTORY_SEPARATOR.'composer.json');
        $hasNpm = is_file($root.DIRECTORY_SEPARATOR.'package.json');

        return [$hasComposer, $hasNpm];
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

    /**
     * @return array<int, string>
     */
    private function securityActions(): array
    {
        return [
            'composer_audit',
            'composer_update',
            'npm_audit',
            'npm_audit_fix',
            'npm_audit_fix_force',
        ];
    }
}
