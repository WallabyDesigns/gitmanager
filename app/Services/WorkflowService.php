<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Workflow;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkflowService
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @return array<int, string>
     */
    public function handleDeployment(Deployment $deployment, Project $project): array
    {
        $messages = [];

        if (! in_array($deployment->status, ['success', 'failed'], true)) {
            return $messages;
        }

        if ($this->hasWorkflowRules()) {
            $workflows = Workflow::query()
                ->where('enabled', true)
                ->orderBy('id')
                ->get();

            foreach ($workflows as $workflow) {
                if (! $this->workflowMatchesDeployment($workflow, $deployment)) {
                    continue;
                }

                $messages = array_merge($messages, $this->deliverWorkflow($workflow, $deployment, $project));
            }

            return $messages;
        }

        if ($deployment->action !== 'deploy') {
            return $messages;
        }

        $event = $deployment->status === 'success' ? 'deploy_success' : 'deploy_failed';

        if ($this->settings->get('workflows.email.enabled', true)) {
            $key = 'workflows.email.events.'.$event;
            if ($this->settings->get($key, true)) {
                $messages = array_merge($messages, $this->sendEmail($deployment, $project));
            }
        }

        if ($this->settings->get('workflows.webhook.enabled', false)) {
            $key = 'workflows.webhook.events.'.$event;
            if ($this->settings->get($key, true)) {
                $messages = array_merge($messages, $this->sendWebhook($deployment, $project));
            }
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    private function deliverWorkflow(Workflow $workflow, Deployment $deployment, Project $project): array
    {
        $messages = [];

        foreach ($workflow->deliveryDefinitions() as $delivery) {
            $type = $delivery['type'] ?? null;

            if ($type === 'email') {
                $messages = array_merge($messages, $this->sendWorkflowEmail($workflow, $delivery, $deployment, $project));

                continue;
            }

            if ($type === 'webhook') {
                $messages = array_merge($messages, $this->sendWorkflowWebhook($workflow, $delivery, $deployment, $project));
            }
        }

        return $messages;
    }

    private function workflowMatchesDeployment(Workflow $workflow, Deployment $deployment): bool
    {
        return in_array($deployment->action, $workflow->triggerActions(), true)
            && in_array($deployment->status, $workflow->triggerStatuses(), true);
    }

    /**
     * @return array<int, string>
     */
    private function sendEmail(Deployment $deployment, Project $project): array
    {
        $messages = [];
        $recipients = $this->resolveRecipients($project);

        if ($recipients === []) {
            return ['Workflow email skipped (no recipients configured).'];
        }

        try {
            $this->settings->applyMailConfig();

            $subject = $deployment->status === 'success'
                ? 'Deployment succeeded: '.$project->name
                : 'Deployment failed: '.$project->name;

            $body = $this->buildEmailBody($deployment, $project);

            Mail::raw($body, function ($message) use ($recipients, $subject) {
                $message->to($recipients)->subject($subject);
            });

            $messages[] = 'Workflow email sent to '.implode(', ', $recipients).'.';
        } catch (\Throwable $exception) {
            $messages[] = 'Workflow email failed: '.$exception->getMessage();
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    private function sendWebhook(Deployment $deployment, Project $project): array
    {
        $messages = [];
        $url = (string) $this->settings->get('workflows.webhook.url', '');
        if ($url === '') {
            return ['Workflow webhook skipped (no URL configured).'];
        }

        $payload = $this->buildWebhookPayload($deployment, $project);

        try {
            $request = Http::timeout(8);

            $secret = (string) $this->settings->getDecrypted('workflows.webhook.secret', '');
            if ($secret !== '') {
                $signature = $this->signPayload($payload, $secret);
                $request = $request->withHeaders([
                    'X-GWM-Signature' => $signature,
                ]);
            }

            $request->post($url, $payload)->throw();
            $messages[] = 'Workflow webhook sent to '.$url.'.';
        } catch (\Throwable $exception) {
            $messages[] = 'Workflow webhook failed: '.$exception->getMessage();
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @return array<int, string>
     */
    private function sendWorkflowEmail(Workflow $workflow, array $delivery, Deployment $deployment, Project $project): array
    {
        $messages = [];
        $recipients = $this->resolveRecipientsForDelivery($delivery, $project);
        $label = $this->deliveryLabel($workflow, $delivery);

        if ($recipients === []) {
            return ['Workflow "'.$workflow->name.'" '.$label.' skipped (no recipients configured).'];
        }

        try {
            $this->settings->applyMailConfig();

            $subject = $this->formatSubject($deployment, $project);
            $body = $this->buildEmailBody($deployment, $project);

            Mail::raw($body, function ($message) use ($recipients, $subject) {
                $message->to($recipients)->subject($subject);
            });

            $messages[] = 'Workflow "'.$workflow->name.'" '.$label.' sent to '.implode(', ', $recipients).'.';
        } catch (\Throwable $exception) {
            $messages[] = 'Workflow "'.$workflow->name.'" '.$label.' failed: '.$exception->getMessage();
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @return array<int, string>
     */
    private function sendWorkflowWebhook(Workflow $workflow, array $delivery, Deployment $deployment, Project $project): array
    {
        $messages = [];
        $url = trim((string) ($delivery['url'] ?? ''));
        $label = $this->deliveryLabel($workflow, $delivery);

        if ($url === '') {
            return ['Workflow "'.$workflow->name.'" '.$label.' skipped (no webhook URL configured).'];
        }

        $payload = $this->buildWebhookPayload($deployment, $project, $workflow, $delivery);

        try {
            $request = Http::timeout(8);
            $secret = $this->decryptDeliverySecret($delivery);
            if ($secret !== '') {
                $signature = $this->signPayload($payload, $secret);
                $request = $request->withHeaders([
                    'X-GWM-Signature' => $signature,
                ]);
            }

            $request->post($url, $payload)->throw();
            $messages[] = 'Workflow "'.$workflow->name.'" '.$label.' sent to '.$url.'.';
        } catch (\Throwable $exception) {
            $messages[] = 'Workflow "'.$workflow->name.'" '.$label.' failed: '.$exception->getMessage();
        }

        return $messages;
    }

    private function hasWorkflowRules(): bool
    {
        if (! Schema::hasTable('workflows')) {
            return false;
        }

        return Workflow::query()->exists();
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
     * @param  array<string, mixed>  $delivery
     * @return array<int, string>
     */
    private function resolveRecipientsForDelivery(array $delivery, Project $project): array
    {
        $recipients = [];

        if ((bool) ($delivery['include_owner'] ?? false) && $project->user?->email) {
            $recipients[] = $project->user->email;
        }

        $extra = (string) ($delivery['recipients'] ?? '');
        if ($extra !== '') {
            $list = preg_split('/[,\n]+/', $extra) ?: [];
            $recipients = array_merge($recipients, array_filter(array_map('trim', $list)));
        }

        return array_values(array_unique(array_filter($recipients)));
    }

    private function buildEmailBody(Deployment $deployment, Project $project): string
    {
        $status = strtoupper($deployment->status);
        $action = $this->formatActionLabel($deployment->action);
        $hashLine = $deployment->to_hash ? 'Commit: '.$deployment->to_hash : 'Commit: n/a';

        return implode("\n", [
            $action.' '.$status,
            'Project: '.$project->name,
            'Repo: '.$project->repo_url,
            'Branch: '.$project->default_branch,
            $hashLine,
            'Started: '.optional($deployment->started_at)->toDateTimeString(),
            'Finished: '.optional($deployment->finished_at)->toDateTimeString(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $delivery
     * @return array<string, mixed>
     */
    private function buildWebhookPayload(Deployment $deployment, Project $project, ?Workflow $workflow = null, ?array $delivery = null): array
    {
        $eventKey = $deployment->action.'_'.$deployment->status;
        $eventStandard = $deployment->action.'.'.$deployment->status;
        $legacyEvent = $deployment->action.'.'.$eventKey;

        $payload = [
            'event' => $legacyEvent,
            'event_key' => $eventKey,
            'event_standard' => $eventStandard,
            'timestamp' => now()->toDateTimeString(),
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'repo' => $project->repo_url,
                'branch' => $project->default_branch,
                'path' => $project->local_path,
                'site_url' => $project->site_url,
            ],
            'deployment' => [
                'id' => $deployment->id,
                'status' => $deployment->status,
                'action' => $deployment->action,
                'from' => $deployment->from_hash,
                'to' => $deployment->to_hash,
                'started_at' => optional($deployment->started_at)->toDateTimeString(),
                'finished_at' => optional($deployment->finished_at)->toDateTimeString(),
            ],
            'links' => [
                'app' => route('projects.index'),
                'project' => route('projects.show', $project),
                'action_center' => route('projects.action-center'),
                'system_updates' => route('system.updates'),
            ],
        ];

        if ($workflow) {
            $payload['workflow'] = [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'channel' => $workflow->channel,
                'action' => $workflow->action,
                'status' => $workflow->status,
                'actions' => $workflow->triggerActions(),
                'statuses' => $workflow->triggerStatuses(),
                'deliveries' => array_map(function (array $workflowDelivery): array {
                    return [
                        'type' => $workflowDelivery['type'] ?? 'email',
                        'name' => trim((string) ($workflowDelivery['name'] ?? '')),
                        'target' => ($workflowDelivery['type'] ?? '') === 'webhook'
                            ? trim((string) ($workflowDelivery['url'] ?? ''))
                            : $this->formatEmailDeliveryTarget($workflowDelivery),
                    ];
                }, $workflow->deliveryDefinitions()),
            ];
        }

        if ($delivery) {
            $payload['destination'] = [
                'type' => $delivery['type'] ?? 'webhook',
                'name' => trim((string) ($delivery['name'] ?? '')),
                'target' => trim((string) ($delivery['url'] ?? '')),
            ];
        }

        return $payload;
    }

    private function formatSubject(Deployment $deployment, Project $project): string
    {
        $action = $this->formatActionLabel($deployment->action);
        $suffix = $deployment->status === 'success' ? 'succeeded' : 'failed';

        return sprintf('%s %s: %s', $action, $suffix, $project->name);
    }

    private function formatActionLabel(string $action): string
    {
        return Str::headline($action);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signPayload(array $payload, string $secret): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), $secret);
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    private function decryptDeliverySecret(array $delivery): string
    {
        $encrypted = $delivery['secret_encrypted'] ?? null;

        if (! is_string($encrypted) || trim($encrypted) === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    private function deliveryLabel(Workflow $workflow, array $delivery): string
    {
        $name = trim((string) ($delivery['name'] ?? ''));
        $type = ($delivery['type'] ?? 'delivery') === 'webhook' ? 'webhook' : 'email';

        if ($name !== '') {
            return $type.' "'.$name.'"';
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    private function formatEmailDeliveryTarget(array $delivery): string
    {
        $parts = [];

        if ((bool) ($delivery['include_owner'] ?? false)) {
            $parts[] = 'Project owner';
        }

        $extra = trim((string) ($delivery['recipients'] ?? ''));
        if ($extra !== '') {
            $list = preg_split('/[,\n]+/', $extra) ?: [];
            $list = array_values(array_filter(array_map('trim', $list)));

            if ($list !== []) {
                $parts[] = count($list) === 1 ? $list[0] : count($list).' extra recipients';
            }
        }

        return $parts !== [] ? implode(' + ', $parts) : 'No recipients configured';
    }
}
