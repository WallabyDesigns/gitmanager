<?php

namespace App\Services;

use App\Models\AuditIssue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class AuditService
{
    public function __construct(
        private readonly DeploymentService $deployments,
        private readonly SettingsService $settings
    ) {}

    /**
     * @param iterable<Project> $projects
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
        $results = $this->deployments->auditDependencies($project, $user, $autoFix);
        $notifications = [];

        foreach ($results as $tool => $result) {
            if (! is_array($result)) {
                continue;
            }

            $record = $this->recordAuditIssue($project, $tool, $result);
            if (! empty($record['notification'])) {
                $notifications[] = $record['notification'];
            }
        }

        $this->maybeAutoCommitFixes($project, $results);
        $project->last_audit_at = now();
        $project->save();

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
     * @param array<string, mixed> $result
     * @return array{opened: bool, resolved: bool, notification?: array<string, mixed>|null}
     */
    private function recordAuditIssue(Project $project, string $tool, array $result): array
    {
        $status = (string) ($result['status'] ?? '');
        $remaining = $result['remaining'] ?? null;
        $found = $result['found'] ?? null;
        $fixed = $result['fixed'] ?? null;
        $summary = $result['summary'] ?? null;
        $fixSummary = $result['fix_summary'] ?? null;
        $severity = $result['severity'] ?? null;

        if ($status === 'failed' && $remaining === null && $found === null) {
            return ['opened' => false, 'resolved' => false, 'notification' => null];
        }

        $latest = AuditIssue::query()
            ->where('project_id', $project->id)
            ->where('tool', $tool)
            ->orderByDesc('id')
            ->first();
        $openIssue = AuditIssue::query()
            ->where('project_id', $project->id)
            ->where('tool', $tool)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();

        if ($remaining !== null && $remaining > 0) {
            if ($openIssue) {
                $openIssue->summary = $summary;
                $openIssue->severity = $severity;
                $openIssue->found_count = is_int($found) ? $found : null;
                $openIssue->fixed_count = is_int($fixed) ? $fixed : null;
                $openIssue->remaining_count = is_int($remaining) ? $remaining : null;
                $openIssue->last_seen_at = now();
                $openIssue->save();

                return ['opened' => false, 'resolved' => false, 'notification' => null];
            }

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

        if ($openIssue) {
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

        return ['opened' => false, 'resolved' => false, 'notification' => null];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $grouped
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
     * @param array<string, array{recipients: array<int, string>, resolved: array<int, array<string, mixed>>, current: array<int, array<string, mixed>>}> $notifications
     */
    private function sendNotifications(array $notifications): void
    {
        if ($notifications === []) {
            return;
        }

        if (! $this->settings->isMailConfigured()) {
            return;
        }

        if (! $this->settings->get('workflows.email.enabled', true)) {
            return;
        }

        if (! $this->settings->get('system.audit_email_enabled', false)) {
            return;
        }

        try {
            $this->settings->applyMailConfig();
        } catch (\Throwable $exception) {
            return;
        }

        foreach ($notifications as $group) {
            $resolved = $group['resolved'] ?? [];
            $current = $group['current'] ?? [];

            if ($resolved === [] && $current === []) {
                continue;
            }

            $subject = $this->buildEmailSubject($resolved, $current);
            $body = $this->buildEmailBody($resolved, $current);

            try {
                Mail::raw($body, function ($message) use ($group, $subject) {
                    $message->to($group['recipients'])->subject($subject);
                });
            } catch (\Throwable $exception) {
                // Swallow mail errors to avoid blocking audits.
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $resolved
     * @param array<int, array<string, mixed>> $current
     */
    private function buildEmailSubject(array $resolved, array $current): string
    {
        if ($resolved !== [] && $current !== []) {
            return sprintf('Audit report: %d resolved, %d open', count($resolved), count($current));
        }

        if ($current !== []) {
            return sprintf('Audit issues detected (%d)', count($current));
        }

        return sprintf('Audit issues resolved (%d)', count($resolved));
    }

    /**
     * @param array<int, array<string, mixed>> $resolved
     * @param array<int, array<string, mixed>> $current
     */
    private function buildEmailBody(array $resolved, array $current): string
    {
        $lines = [
            'Git Web Manager audit report',
            'Checked: '.now()->toDateTimeString(),
            '',
        ];

        if ($resolved !== []) {
            $lines[] = 'Resolved issues:';
            foreach ($resolved as $index => $entry) {
                $project = $entry['project'];
                $label = $this->formatTool($entry['tool'] ?? 'audit');
                $summary = (string) ($entry['fix_summary'] ?? $entry['summary'] ?? 'Resolved audit issue');
                $lines[] = sprintf('%d. %s (%s)', $index + 1, $project->name, $label);
                $lines[] = '   '.$summary;
            }
            $lines[] = '';
        }

        if ($current !== []) {
            $lines[] = 'Current issues:';
            foreach ($current as $index => $entry) {
                $project = $entry['project'];
                $label = $this->formatTool($entry['tool'] ?? 'audit');
                $summary = (string) ($entry['summary'] ?? 'Open audit issue');
                $remaining = $entry['remaining'] ?? null;
                $lines[] = sprintf('%d. %s (%s)', $index + 1, $project->name, $label);
                $lines[] = '   '.$summary;
                if (is_numeric($remaining)) {
                    $lines[] = '   Remaining: '.$remaining;
                }
                if (! empty($entry['severity'])) {
                    $lines[] = '   Severity: '.$entry['severity'];
                }
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    private function formatTool(string $tool): string
    {
        return match (strtolower($tool)) {
            'npm' => 'Npm audit',
            'composer' => 'Composer audit',
            default => ucfirst($tool),
        };
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

    /**
     * @param array<string, array<string, mixed>> $results
     */
    private function maybeAutoCommitFixes(Project $project, array $results): void
    {
        if (! $this->settings->get('system.audit_auto_commit', false)) {
            return;
        }

        $fixSummaries = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            if (! ($result['fix_applied'] ?? false)) {
                continue;
            }

            if (($result['remaining'] ?? null) !== 0) {
                continue;
            }

            $found = $result['found'] ?? null;
            if (is_numeric($found) && (int) $found === 0) {
                continue;
            }

            $summary = (string) ($result['fix_summary'] ?? $result['summary'] ?? '');
            if ($summary !== '') {
                $fixSummaries[] = $summary;
            }
        }

        if ($fixSummaries === []) {
            return;
        }

        $changes = $this->deployments->getWorkingTreeChanges($project);
        if (! ($changes['dirty'] ?? false)) {
            return;
        }

        $paths = $this->filterDependencyFiles($changes['files'] ?? []);
        if ($paths === []) {
            return;
        }

        $message = $this->buildAuditCommitMessage($fixSummaries);

        try {
            $this->deployments->commitAndPush($project, $message, $paths);
        } catch (\Throwable $exception) {
            // Swallow git errors to avoid failing audits.
        }
    }

    /**
     * @param array<int, string> $files
     * @return array<int, string>
     */
    private function filterDependencyFiles(array $files): array
    {
        $targets = [
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'npm-shrinkwrap.json',
            'pnpm-lock.yaml',
            'yarn.lock',
        ];

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
     * @param array<int, string> $summaries
     */
    private function buildAuditCommitMessage(array $summaries): string
    {
        $summary = trim(implode('; ', array_filter(array_map('trim', $summaries))));
        $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;

        if (strlen($summary) > 120) {
            $summary = substr($summary, 0, 117).'...';
        }

        return 'Git Web Manager Vulnerability fixes: '.$summary;
    }
}
