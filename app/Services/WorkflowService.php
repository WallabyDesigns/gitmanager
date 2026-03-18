<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
                $messages = array_merge($messages, $this->sendWebhook($deployment, $project, $event));
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
    private function sendWebhook(Deployment $deployment, Project $project, string $event): array
    {
        $messages = [];
        $url = (string) $this->settings->get('workflows.webhook.url', '');
        if ($url === '') {
            return ['Workflow webhook skipped (no URL configured).'];
        }

        $payload = [
            'event' => 'deploy.'.$event,
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

    private function buildEmailBody(Deployment $deployment, Project $project): string
    {
        $status = strtoupper($deployment->status);
        $hashLine = $deployment->to_hash ? 'Commit: '.$deployment->to_hash : 'Commit: n/a';

        return implode("\n", [
            'Deployment '.$status,
            'Project: '.$project->name,
            'Repo: '.$project->repo_url,
            'Branch: '.$project->default_branch,
            $hashLine,
            'Started: '.optional($deployment->started_at)->toDateTimeString(),
            'Finished: '.optional($deployment->finished_at)->toDateTimeString(),
        ]);
    }
}
