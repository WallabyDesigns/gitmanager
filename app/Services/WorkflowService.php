<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Workflow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkflowService
{
    public function __construct(private SettingsService $settings)
    {
    }

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
                ->where('action', $deployment->action)
                ->where('status', $deployment->status)
                ->orderBy('id')
                ->get();

            foreach ($workflows as $workflow) {
                if ($workflow->channel === 'email') {
                    $messages = array_merge($messages, $this->sendWorkflowEmail($workflow, $deployment, $project));
                    continue;
                }

                if ($workflow->channel === 'webhook') {
                    $messages = array_merge($messages, $this->sendWorkflowWebhook($workflow, $deployment, $project));
                }
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
                $signature = hash_hmac('sha256', json_encode($payload), $secret);
                $request = $request->withHeaders([
                    'X-GWM-Signature' => $signature,
                ]);
            }

            $request->post($url, $payload);
            $messages[] = 'Workflow webhook sent to '.$url.'.';
        } catch (\Throwable $exception) {
            $messages[] = 'Workflow webhook failed: '.$exception->getMessage();
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    private function sendWorkflowEmail(Workflow $workflow, Deployment $deployment, Project $project): array
    {
        $messages = [];
        $recipients = $this->resolveRecipientsForWorkflow($workflow, $project);

        if ($recipients === []) {
            return ['Workflow "'.$workflow->name.'" skipped (no recipients configured).'];
        }

        try {
            $this->settings->applyMailConfig();

            $subject = $this->formatSubject($deployment, $project);
            $body = $this->buildEmailBody($deployment, $project);

            Mail::raw($body, function ($message) use ($recipients, $subject) {
                $message->to($recipients)->subject($subject);
            });

            $messages[] = 'Workflow "'.$workflow->name.'" email sent to '.implode(', ', $recipients).'.';
        } catch (\Throwable $exception) {
            $messages[] = 'Workflow "'.$workflow->name.'" email failed: '.$exception->getMessage();
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    private function sendWorkflowWebhook(Workflow $workflow, Deployment $deployment, Project $project): array
    {
        $messages = [];
        $url = trim((string) $workflow->webhook_url);

        if ($url === '') {
            return ['Workflow "'.$workflow->name.'" skipped (no webhook URL configured).'];
        }

        $payload = $this->buildWebhookPayload($deployment, $project, $workflow);

        try {
            $request = Http::timeout(8);
            $secret = (string) ($workflow->webhook_secret ?? '');
            if ($secret !== '') {
                $signature = hash_hmac('sha256', json_encode($payload), $secret);
                $request = $request->withHeaders([
                    'X-GWM-Signature' => $signature,
                ]);
            }

            $request->post($url, $payload);
            $messages[] = 'Workflow "'.$workflow->name.'" webhook sent to '.$url.'.';
        } catch (\Throwable $exception) {
            $messages[] = 'Workflow "'.$workflow->name.'" webhook failed: '.$exception->getMessage();
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
     * @return array<int, string>
     */
    private function resolveRecipientsForWorkflow(Workflow $workflow, Project $project): array
    {
        $recipients = [];

        if ($workflow->include_owner && $project->user?->email) {
            $recipients[] = $project->user->email;
        }

        $extra = (string) ($workflow->recipients ?? '');
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
     * @return array<string, mixed>
     */
    private function buildWebhookPayload(Deployment $deployment, Project $project, ?Workflow $workflow = null): array
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
        ];

        if ($workflow) {
            $payload['workflow'] = [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'channel' => $workflow->channel,
                'action' => $workflow->action,
                'status' => $workflow->status,
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
}
