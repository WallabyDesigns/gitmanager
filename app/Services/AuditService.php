<?php

namespace App\Services;

use App\Models\AuditIssue;
use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class AuditService
{
    private ?bool $auditTimestampAvailable = null;

    private ?bool $lastEmailedColumnAvailable = null;

    public function __construct(
        private readonly DeploymentService $deployments,
        private readonly DeploymentQueueService $queue,
        private readonly SettingsService $settings,
        private readonly EmailDigestService $digest,
    ) {}

    /**
     * @param  iterable<Project>  $projects
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function auditProjects(iterable $projects, ?User $user = null, bool $autoFix = true, bool $sendEmail = true): array
    {
        $results = [];
        $notifications = [];

        foreach ($projects as $project) {
            if (! $project instanceof Project) {
                continue;
            }

            $payload = $this->auditProject($project, $user, $autoFix, false);
            $results[$project->id] = $payload['results'] ?? [];

            $entries = $payload['notifications'] ?? [];
            if ($entries !== []) {
                $this->collectNotifications($entries, $notifications);
            }
        }

        if ($sendEmail) {
            $this->sendNotifications($notifications);
        }

        return $results;
    }

    /**
     * @return array{results: array<string, array<string, mixed>>, notifications: array<int, array<string, mixed>>}
     */
    public function auditProject(Project $project, ?User $user = null, bool $autoFix = true, bool $sendEmail = false): array
    {
        // Scan only — fixes are queued separately so they don't block the scheduler.
        $results = $this->deployments->auditDependencies($project, $user, false);
        $notifications = [];

        foreach ($results as $tool => $result) {
            if (! is_array($result)) {
                continue;
            }

            $record = $this->recordAuditIssue($project, $tool, $result, $autoFix);
            if (! empty($record['notification'])) {
                $notifications[] = $record['notification'];
            }
        }

        $this->markAuditTimestamp($project);

        if ($sendEmail && $notifications !== []) {
            $grouped = [];
            $this->collectNotifications($notifications, $grouped);
            $this->sendNotifications($grouped);
        }

        return [
            'results' => $results,
            'notifications' => $notifications,
        ];
    }

    /**
     * Send one digest email covering all projects: open issues that are not
     * awaiting an automated fix and have cleared the email cooldown, plus
     * recently resolved issues that have not been announced yet.
     */
    public function sendPendingDigest(): void
    {
        $notifications = [];
        $this->collectNotifications($this->pendingDigestEntries(), $notifications);
        $this->sendNotifications($notifications);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingDigestEntries(): array
    {
        if (! $this->hasLastEmailedColumn()) {
            return [];
        }

        $cooldownHours = 1;
        $entries = [];

        $openIssues = AuditIssue::query()
            ->with('project.user')
            ->where('status', 'open')
            ->where('remaining_count', '>', 0)
            ->when($cooldownHours > 0, fn ($query) => $query->where(function ($q) use ($cooldownHours) {
                $q->whereNull('last_emailed_at')
                    ->orWhere('last_emailed_at', '<', now()->subHours($cooldownHours));
            }))
            ->get();

        foreach ($openIssues as $issue) {
            if (! $issue->project || $this->hasPendingFix($issue->project, $issue->tool)) {
                continue;
            }

            $entries[] = [
                'type' => 'open',
                'project' => $issue->project,
                'tool' => $issue->tool,
                'summary' => $issue->summary,
                'remaining' => $issue->remaining_count,
                'severity' => $issue->severity,
                'issue' => $issue,
            ];
        }

        $resolvedIssues = AuditIssue::query()
            ->with('project.user')
            ->where('status', 'resolved')
            ->where('resolved_at', '>=', now()->subDays(2))
            ->where(function ($q) {
                $q->whereNull('last_emailed_at')->orWhereColumn('last_emailed_at', '<', 'resolved_at');
            })
            ->get();

        foreach ($resolvedIssues as $issue) {
            if (! $issue->project) {
                continue;
            }

            $entries[] = [
                'type' => 'resolved',
                'project' => $issue->project,
                'tool' => $issue->tool,
                'summary' => $issue->summary,
                'fix_summary' => $issue->fix_summary,
                'issue' => $issue,
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{opened: bool, resolved: bool, notification?: array<string, mixed>|null}
     */
    private function recordAuditIssue(Project $project, string $tool, array $result, bool $queueFix = true): array
    {
        if ((string) ($result['status'] ?? '') === 'failed') {
            return ['opened' => false, 'resolved' => false, 'notification' => null];
        }

        $remaining = $result['remaining'] ?? null;
        $openIssue = AuditIssue::query()
            ->where('project_id', $project->id)
            ->where('tool', $tool)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();

        if ($remaining !== null && $remaining > 0) {
            return $openIssue
                ? $this->handleOpenIssueWithRemaining($project, $tool, $result, $openIssue, $queueFix)
                : $this->handleNewIssue($project, $tool, $result, $queueFix);
        }

        return $this->handleResolved($project, $tool, $result, $openIssue);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{opened: bool, resolved: bool, notification?: array<string, mixed>|null}
     */
    private function handleOpenIssueWithRemaining(Project $project, string $tool, array $result, AuditIssue $openIssue, bool $queueFix): array
    {
        $remaining = $result['remaining'] ?? null;
        $found = $result['found'] ?? null;
        $fixed = $result['fixed'] ?? null;
        $summary = $result['summary'] ?? null;
        $severity = $result['severity'] ?? null;

        $openIssue->summary = $summary;
        $openIssue->severity = $severity;
        $openIssue->found_count = is_int($found) ? $found : null;
        $openIssue->fixed_count = is_int($fixed) ? $fixed : null;
        $openIssue->remaining_count = is_int($remaining) ? $remaining : null;
        $openIssue->last_seen_at = now();
        $openIssue->save();

        // A fix is still queued or running — let it finish before notifying.
        if ($queueFix && $this->hasPendingFix($project, $tool)) {
            return ['opened' => false, 'resolved' => false, 'notification' => null];
        }

        // Cooldown: check if this specific issue was emailed recently.
        if ($this->issueWasRecentlyEmailed($openIssue)) {
            return ['opened' => false, 'resolved' => false, 'notification' => null];
        }

        return [
            'opened' => false,
            'resolved' => false,
            'notification' => [
                'type' => 'open',
                'project' => $project,
                'tool' => $tool,
                'summary' => $summary,
                'remaining' => $remaining,
                'severity' => $severity,
                'issue' => $openIssue,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{opened: bool, resolved: bool, notification?: array<string, mixed>|null}
     */
    private function handleNewIssue(Project $project, string $tool, array $result, bool $queueFix): array
    {
        $remaining = $result['remaining'] ?? null;
        $found = $result['found'] ?? null;
        $fixed = $result['fixed'] ?? null;
        $summary = $result['summary'] ?? null;
        $severity = $result['severity'] ?? null;

        $issue = AuditIssue::create([
            'project_id' => $project->id,
            'tool' => $tool,
            'status' => 'open',
            'severity' => $severity,
            'summary' => $summary,
            'found_count' => is_int($found) ? $found : null,
            'fixed_count' => is_int($fixed) ? $fixed : null,
            'remaining_count' => is_int($remaining) ? $remaining : null,
            'detected_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Queue the fix first — hold notification until we know the outcome.
        if ($queueFix && $this->queueFixForTool($project, $tool)) {
            return ['opened' => true, 'resolved' => false, 'notification' => null];
        }

        // Queue unavailable or autoFix off — notify immediately (with cooldown).
        if ($this->wasRecentlyEmailed($project, $tool, $issue->id)) {
            return ['opened' => true, 'resolved' => false, 'notification' => null];
        }

        return [
            'opened' => true,
            'resolved' => false,
            'notification' => [
                'type' => 'open',
                'project' => $project,
                'tool' => $tool,
                'summary' => $summary,
                'remaining' => $remaining,
                'severity' => $severity,
                'issue' => $issue,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{opened: bool, resolved: bool, notification?: array<string, mixed>|null}
     */
    private function handleResolved(Project $project, string $tool, array $result, ?AuditIssue $openIssue): array
    {
        if (! $openIssue) {
            return ['opened' => false, 'resolved' => false, 'notification' => null];
        }

        $found = $result['found'] ?? null;
        $fixed = $result['fixed'] ?? null;
        $summary = $result['summary'] ?? null;
        $fixSummary = $result['fix_summary'] ?? null;

        $openIssue->status = 'resolved';
        $openIssue->fix_summary = $fixSummary ?: $summary;
        $openIssue->found_count = is_int($found) ? $found : $openIssue->found_count;
        $openIssue->fixed_count = is_int($fixed) ? $fixed : $openIssue->fixed_count;
        $openIssue->remaining_count = 0;
        $openIssue->resolved_at = now();
        $openIssue->last_seen_at = now();
        $openIssue->save();

        return [
            'opened' => false,
            'resolved' => true,
            'notification' => [
                'type' => 'resolved',
                'project' => $project,
                'tool' => $tool,
                'summary' => $summary,
                'fix_summary' => $openIssue->fix_summary,
                'issue' => $openIssue,
            ],
        ];
    }

    /**
     * Queue the appropriate fix action for a tool.
     * Returns true if a fix was enqueued, false if the queue is disabled or no fix action exists.
     */
    private function queueFixForTool(Project $project, string $tool): bool
    {
        if (! config('gitmanager.deploy_queue.enabled', true)) {
            return false;
        }

        $action = match ($tool) {
            'npm' => 'npm_audit_fix',
            'composer' => 'composer_update',
            default => null,
        };

        if ($action === null) {
            return false;
        }

        $this->queue->enqueue($project, $action, ['reason' => 'audit_fix']);

        return true;
    }

    /**
     * Returns true if a relevant fix job is queued or running for the given project and tool.
     */
    private function hasPendingFix(Project $project, string $tool): bool
    {
        $actions = match ($tool) {
            'npm' => ['npm_audit_fix', 'npm_audit_fix_force', 'dependency_update'],
            'composer' => ['composer_update', 'dependency_update'],
            default => [],
        };

        if ($actions === []) {
            return false;
        }

        return DeploymentQueueItem::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ['queued', 'running'])
            ->whereIn('action', $actions)
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<string, array<string, mixed>>  $grouped
     */
    private function collectNotifications(array $entries, array &$grouped): void
    {
        foreach ($entries as $entry) {
            if (! isset($entry['project']) || ! $entry['project'] instanceof Project) {
                continue;
            }

            $project = $entry['project'];
            $recipients = $this->resolveRecipients($project);
            if ($recipients === []) {
                continue;
            }

            sort($recipients);
            $key = implode('|', $recipients);
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'recipients' => $recipients,
                    'resolved' => [],
                    'current' => [],
                ];
            }

            if (($entry['type'] ?? '') === 'resolved') {
                $grouped[$key]['resolved'][] = $entry;
            } else {
                $grouped[$key]['current'][] = $entry;
            }
        }
    }

    /**
     * @param  array<string, array{recipients: array<int, string>, resolved: array<int, array<string, mixed>>, current: array<int, array<string, mixed>>}>  $notifications
     */
    private function sendNotifications(array $notifications): void
    {
        if ($notifications === []) {
            return;
        }

        if (! $this->settings->get('system.audit_email_enabled', false)) {
            return;
        }

        foreach ($notifications as $group) {
            $resolved = $group['resolved'] ?? [];
            $current = $group['current'] ?? [];

            if ($resolved === [] && $current === []) {
                continue;
            }

            foreach ($resolved as $entry) {
                $this->queueAuditNotification($entry, $group['recipients'], 'resolved');
            }
            foreach ($current as $entry) {
                $this->queueAuditNotification($entry, $group['recipients'], 'open');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $recipients
     */
    private function queueAuditNotification(array $entry, array $recipients, string $type): void
    {
        $issue = $entry['issue'] ?? null;
        $project = $entry['project'] ?? null;

        if ($issue instanceof AuditIssue && $project instanceof Project) {
            $this->digest->queueAuditIssue($issue, $project, $recipients, $type);
        }
    }

    private function wasRecentlyEmailed(Project $project, string $tool, int $excludeIssueId): bool
    {
        $cooldownHours = 1;

        return $cooldownHours > 0
            && $this->hasLastEmailedColumn()
            && AuditIssue::query()
                ->where('project_id', $project->id)
                ->where('tool', $tool)
                ->where('id', '!=', $excludeIssueId)
                ->whereNotNull('last_emailed_at')
                ->where('last_emailed_at', '>=', now()->subHours($cooldownHours))
                ->exists();
    }

    private function issueWasRecentlyEmailed(AuditIssue $issue): bool
    {
        $cooldownHours = 1;

        return $cooldownHours > 0
            && $this->hasLastEmailedColumn()
            && $issue->last_emailed_at !== null
            && $issue->last_emailed_at->gte(now()->subHours($cooldownHours));
    }

    private function hasLastEmailedColumn(): bool
    {
        if ($this->lastEmailedColumnAvailable !== null) {
            return $this->lastEmailedColumnAvailable;
        }

        try {
            $this->lastEmailedColumnAvailable = Schema::hasColumn('audit_issues', 'last_emailed_at');
        } catch (\Throwable $exception) {
            $this->lastEmailedColumnAvailable = false;
        }

        return $this->lastEmailedColumnAvailable;
    }

    /**
     * @return array<int, string>
     */
    private function resolveRecipients(Project $project): array
    {
        $recipients = [];

        if ($this->settings->get('workflows.email.include_project_owner', true) && $project->user?->email) {
            $recipients[] = $project->user->email;
        }

        $extra = (string) $this->settings->get('workflows.email.recipients', '');
        if ($extra !== '') {
            $list = array_filter(array_map('trim', explode(',', $extra)));
            $recipients = array_merge($recipients, $list);
        }

        return array_values(array_unique(array_filter($recipients)));
    }

    private function markAuditTimestamp(Project $project): void
    {
        if (! $this->hasAuditTimestampColumn()) {
            return;
        }

        $project->last_audit_at = now();
        $project->save();
    }

    private function hasAuditTimestampColumn(): bool
    {
        if ($this->auditTimestampAvailable !== null) {
            return $this->auditTimestampAvailable;
        }

        try {
            $this->auditTimestampAvailable = Schema::hasColumn('projects', 'last_audit_at');
        } catch (\Throwable $exception) {
            $this->auditTimestampAvailable = false;
        }

        return $this->auditTimestampAvailable;
    }
}
