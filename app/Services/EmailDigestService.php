<?php

namespace App\Services;

use App\Mail\SystemNotificationMail;
use App\Models\AuditIssue;
use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Models\EmailDigestEntry;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailDigestService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array<int, string>  $recipients
     * @param  array<string, mixed>  $details
     */
    public function queue(string $sourceKey, string $category, array $recipients, string $summary, array $details = [], ?Project $project = null): void
    {
        $recipients = $this->normalizeRecipients($recipients);
        if ($recipients === [] || ! $this->isEnabled()) {
            return;
        }

        $attributes = [
            'project_id' => $project?->id,
            'recipient_key' => $this->recipientKey($recipients),
            'recipients' => $recipients,
            'category' => $category,
            'summary' => $summary,
            'details' => $details,
            'occurred_at' => now(),
        ];

        $entry = EmailDigestEntry::query()->firstOrNew(['source_key' => $sourceKey]);
        if (! $entry->exists || $entry->sent_at === null) {
            $entry->fill($attributes)->save();
        }
    }

    /**
     * @param  array<int, string>  $recipients
     */
    public function queueDeployment(Deployment $deployment, Project $project, array $recipients, string $sourceSuffix = 'default'): void
    {
        $status = $deployment->status === 'success' ? 'succeeded' : 'failed';
        $action = Str::headline($deployment->action);
        $summary = sprintf('%s %s for %s.', $action, $status, $project->name);

        $this->queue(
            'deployment:'.$deployment->id.':'.sha1($sourceSuffix.':'.$this->recipientKey($recipients)),
            'deployment',
            $recipients,
            $summary,
            [
                'action' => $action,
                'status' => $deployment->status,
                'project' => $project->name,
                'deployment_id' => $deployment->id,
                'site_url' => $project->site_url,
                'commit' => $deployment->to_hash,
                'finished_at' => $deployment->finished_at?->toDateTimeString(),
            ],
            $project,
        );
    }

    /**
     * @param  array<int, string>  $recipients
     */
    public function queueQueueFailure(DeploymentQueueItem $item, Project $project, array $recipients): void
    {
        $action = Str::headline($item->action);
        $this->queue(
            'queue-failure:'.($item->deployment_id ?: $item->id).':'.$this->recipientKey($recipients),
            'queue_failure',
            $recipients,
            sprintf('Queued %s task failed for %s.', $action, $project->name),
            [
                'action' => $action,
                'project' => $project->name,
                'deployment_id' => $item->deployment_id,
                'site_url' => $project->site_url,
                'failed_at' => $item->finished_at?->toDateTimeString(),
                'output' => $this->outputTail((string) ($item->deployment?->output_log ?? '')),
            ],
            $project,
        );
    }

    /**
     * @param  array<int, string>  $recipients
     */
    public function queueAuditIssue(AuditIssue $issue, Project $project, array $recipients, string $type): void
    {
        $resolved = $type === 'resolved';
        $summary = $resolved
            ? sprintf('%s audit issue resolved for %s.', strtoupper($issue->tool), $project->name)
            : sprintf('%s audit found %d remaining issue(s) for %s.', strtoupper($issue->tool), (int) $issue->remaining_count, $project->name);

        $timestamp = $resolved ? $issue->resolved_at : $issue->detected_at;
        $this->queue(
            sprintf('audit:%d:%s:%s:%s', $issue->id, $type, $timestamp?->timestamp ?? 0, $this->recipientKey($recipients)),
            'audit_'.$type,
            $recipients,
            $summary,
            [
                'audit_issue_id' => $issue->id,
                'tool' => strtoupper($issue->tool),
                'project' => $project->name,
                'severity' => $issue->severity,
                'summary' => $issue->summary,
                'fix_summary' => $issue->fix_summary,
                'remaining' => $issue->remaining_count,
            ],
            $project,
        );
    }

    public function sendDueDigests(): int
    {
        if (! $this->isEnabled() || ! $this->settings->isMailConfigured()) {
            return 0;
        }

        try {
            $this->settings->applyMailConfig();
        } catch (\Throwable) {
            return 0;
        }

        $sent = 0;
        EmailDigestEntry::query()
            ->whereNull('sent_at')
            ->orderBy('id')
            ->get()
            ->groupBy('recipient_key')
            ->each(function (Collection $entries) use (&$sent): void {
                $first = $entries->first();
                if (! $first instanceof EmailDigestEntry || Cache::has($this->cooldownKey($first->recipient_key))) {
                    return;
                }

                $consolidatedEntries = $this->consolidateEntries($entries);

                try {
                    Mail::to($first->recipients)->send(new SystemNotificationMail(
                        $this->subject($consolidatedEntries),
                        __('Activity report'),
                        __('Your scheduled activity report is ready. Related events are consolidated below.'),
                        $this->mailItems($consolidatedEntries),
                        route('processes.queue'),
                        __('Review activity'),
                        $this->showEnterpriseSuggestion(),
                    ));
                } catch (\Throwable $exception) {
                    Log::error('Email digest delivery failed.', [
                        'recipient_key' => $first->recipient_key,
                        'entry_ids' => $entries->pluck('id')->all(),
                        'exception' => $exception,
                    ]);

                    return;
                }

                $now = now();
                EmailDigestEntry::query()->whereIn('id', $entries->pluck('id'))->update(['sent_at' => $now]);
                $this->stampAuditIssues($entries, $now);
                Cache::put($this->cooldownKey($first->recipient_key), true, now()->addMinutes($this->cooldownMinutes()));
                $sent++;
            });

        return $sent;
    }

    /** @param Collection<int, EmailDigestEntry> $entries */
    private function subject(?Collection $entries): string
    {
        $count = $entries?->count() ?? 0;

        return $count === 1 ? 'Git Web Manager activity report' : sprintf('Git Web Manager activity report (%d updates)', $count);
    }

    /** @param Collection<int, EmailDigestEntry> $entries */
    private function consolidateEntries(Collection $entries): Collection
    {
        return $entries
            ->sortByDesc(fn (EmailDigestEntry $entry) => [$entry->occurred_at?->getTimestamp() ?? 0, $entry->id])
            ->unique(fn (EmailDigestEntry $entry) => $this->consolidationKey($entry))
            ->sortBy('id')
            ->values();
    }

    /** @param Collection<int, EmailDigestEntry> $entries */
    private function mailItems(Collection $entries): array
    {
        return $entries->map(function (EmailDigestEntry $entry): array {
            $details = (array) $entry->details;
            $errorLog = $details['output'] ?? null;
            unset($details['output'], $details['deployment_id'], $details['audit_issue_id']);

            return [
                'title' => $entry->summary,
                'fields' => $details,
                'error_log' => is_string($errorLog) ? $errorLog : null,
            ];
        })->all();
    }

    private function consolidationKey(EmailDigestEntry $entry): string
    {
        $details = (array) $entry->details;
        if (in_array($entry->category, ['deployment', 'queue_failure'], true) && ! empty($details['deployment_id'])) {
            return 'deployment:'.$details['deployment_id'];
        }

        if (in_array($entry->category, ['deployment', 'queue_failure'], true)
            && ($entry->project_id !== null || ! empty($details['project']) || ! empty($details['action']))) {
            return sha1(implode('|', [
                'deployment-failure',
                $entry->project_id,
                strtolower(trim((string) ($details['project'] ?? ''))),
                strtolower(trim((string) ($details['action'] ?? 'deployment'))),
            ]));
        }

        if (str_starts_with($entry->category, 'audit_') && ! empty($details['audit_issue_id'])) {
            return 'audit:'.$details['audit_issue_id'];
        }

        return sha1(implode('|', [$entry->category, $entry->project_id, $entry->summary]));
    }

    private function showEnterpriseSuggestion(): bool
    {
        return strtolower((string) $this->settings->get('system.license.edition', 'community')) !== EditionService::ENTERPRISE;
    }

    /** @param Collection<int, EmailDigestEntry> $entries */
    private function stampAuditIssues(Collection $entries, \DateTimeInterface $sentAt): void
    {
        $ids = $entries->filter(fn (EmailDigestEntry $entry) => str_starts_with($entry->category, 'audit_'))
            ->map(fn (EmailDigestEntry $entry) => (int) ($entry->details['audit_issue_id'] ?? 0))
            ->filter()
            ->all();

        if ($ids !== []) {
            AuditIssue::query()->whereIn('id', $ids)->update(['last_emailed_at' => $sentAt]);
        }
    }

    /** @param array<int, string> $recipients */
    private function recipientKey(array $recipients): string
    {
        $recipients = $this->normalizeRecipients($recipients);

        return hash('sha256', implode('|', $recipients));
    }

    /** @param array<int, string> $recipients */
    private function normalizeRecipients(array $recipients): array
    {
        $recipients = array_values(array_unique(array_filter(array_map(
            static fn ($recipient) => strtolower(trim((string) $recipient)),
            $recipients,
        ), static fn (string $recipient): bool => filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false)));
        sort($recipients);

        return $recipients;
    }

    private function isEnabled(): bool
    {
        return (bool) $this->settings->get('workflows.email.enabled', true)
            && (bool) $this->settings->get('system.email_digest_enabled', config('gitmanager.email_digest.enabled', true));
    }

    private function cooldownMinutes(): int
    {
        return max(1, (int) $this->settings->get(
            'system.email_digest_cooldown_minutes',
            config('gitmanager.email_digest.cooldown_minutes', 60),
        ));
    }

    private function cooldownKey(string $recipientKey): string
    {
        return 'gwm_email_digest_cooldown_'.$recipientKey;
    }

    private function outputTail(string $output): string
    {
        $output = trim($output);

        return mb_strlen($output) > 1200 ? "[Showing the last 1,200 characters]\n...".mb_substr($output, -1200) : $output;
    }
}
