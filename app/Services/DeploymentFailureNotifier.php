<?php

namespace App\Services;

use App\Models\DeploymentQueueItem;
use Illuminate\Support\Facades\Mail;

class DeploymentFailureNotifier
{
    private const OUTPUT_TAIL_CHARS = 2000;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Email the project owner / configured recipients when a queued task fails.
     * Best-effort: mail errors must never break queue processing.
     */
    public function notify(DeploymentQueueItem $item): void
    {
        try {
            if (! (bool) $this->settings->get('workflows.email.notify_queue_failures', true)) {
                return;
            }

            $project = $item->project;
            if (! $project) {
                return;
            }

            $recipients = $this->resolveRecipients($project->user?->email);
            if ($recipients === []) {
                return;
            }

            $action = ucfirst(str_replace('_', ' ', $item->action));
            $subject = sprintf('Task failed: %s — %s', $action, $project->name);
            $body = $this->buildBody($item, $action);

            Mail::raw($body, function ($message) use ($recipients, $subject) {
                $message->to($recipients)->subject($subject);
            });
        } catch (\Throwable) {
            // Swallow mail errors to avoid breaking queue processing.
        }
    }

    private function buildBody(DeploymentQueueItem $item, string $action): string
    {
        $project = $item->project;
        $lines = [
            'A queued task failed.',
            '',
            'Project: '.$project->name,
            'Action: '.$action,
            'Queued at: '.($item->created_at?->toDateTimeString() ?? 'unknown'),
            'Failed at: '.($item->finished_at?->toDateTimeString() ?? now()->toDateTimeString()),
        ];

        if ($project->site_url) {
            $lines[] = 'Site: '.$project->site_url;
        }

        $output = trim((string) ($item->deployment?->output_log ?? ''));
        if ($output !== '') {
            if (strlen($output) > self::OUTPUT_TAIL_CHARS) {
                $output = '…'.substr($output, -self::OUTPUT_TAIL_CHARS);
            }

            $lines[] = '';
            $lines[] = 'Last output:';
            $lines[] = $output;
        }

        $lines[] = '';
        $lines[] = 'Review the task queue: '.route('processes.queue');

        return implode("\n", $lines);
    }

    /**
     * @return array<int, string>
     */
    private function resolveRecipients(?string $ownerEmail): array
    {
        $recipients = [];

        if ($this->settings->get('workflows.email.include_project_owner', true) && $ownerEmail) {
            $recipients[] = $ownerEmail;
        }

        $extra = (string) $this->settings->get('workflows.email.recipients', '');
        if ($extra !== '') {
            $list = array_filter(array_map('trim', explode(',', $extra)));
            $recipients = array_merge($recipients, $list);
        }

        return array_values(array_unique(array_filter($recipients, fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }
}
