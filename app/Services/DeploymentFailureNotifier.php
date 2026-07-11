<?php

namespace App\Services;

use App\Models\DeploymentQueueItem;

class DeploymentFailureNotifier
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly EmailDigestService $digest,
    ) {}

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

            $this->digest->queueQueueFailure($item, $project, $recipients);
        } catch (\Throwable) {
            // Swallow mail errors to avoid breaking queue processing.
        }
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
