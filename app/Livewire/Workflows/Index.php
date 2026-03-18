<?php

namespace App\Livewire\Workflows;

use App\Models\Workflow;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Component;

class Index extends Component
{
    public ?int $editingId = null;
    public string $name = '';
    public string $action = 'deploy';
    public string $status = 'success';
    public string $channel = 'email';
    public bool $enabled = true;
    public bool $includeOwner = true;
    public string $recipients = '';
    public string $webhookUrl = '';
    public string $webhookSecret = '';

    public string $testWebhookUrl = '';
    public string $testEmail = '';

    public array $actionOptions = [
        'deploy' => 'Deploy',
        'rollback' => 'Rollback',
        'dependency_update' => 'Dependency Update',
        'preview_build' => 'Preview Build',
        'composer_install' => 'Composer Install',
        'composer_update' => 'Composer Update',
        'composer_audit' => 'Composer Audit',
        'npm_install' => 'Npm Install',
        'npm_update' => 'Npm Update',
        'npm_audit_fix' => 'Npm Audit Fix',
        'npm_audit_fix_force' => 'Npm Audit Fix (Force)',
        'custom_command' => 'Custom Command',
        'app_clear_cache' => 'App Clear Cache',
    ];

    public array $statusOptions = [
        'success' => 'Success',
        'failed' => 'Failed',
    ];

    public array $channelOptions = [
        'email' => 'Email',
        'webhook' => 'Webhook',
    ];

    public string $tab = 'list';

    public function render()
    {
        $settings = app(SettingsService::class);

        return view('livewire.workflows.index', [
            'workflows' => Workflow::query()->orderByDesc('created_at')->get(),
            'mailConfigured' => $settings->isMailConfigured(),
            'showMailSettingsLink' => auth()->user()?->isAdmin() ?? false,
        ])
            ->layout('layouts.app', [
                'header' => view('livewire.workflows.partials.header'),
            ]);
    }

    public function saveWorkflow(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'action' => ['required', 'string'],
            'status' => ['required', 'string'],
            'channel' => ['required', 'string'],
            'recipients' => ['nullable', 'string'],
            'webhookUrl' => ['nullable', 'url'],
            'webhookSecret' => ['nullable', 'string'],
        ]);

        if (! array_key_exists($this->action, $this->actionOptions)) {
            $this->addError('action', 'Invalid action selected.');
            return;
        }

        if (! array_key_exists($this->status, $this->statusOptions)) {
            $this->addError('status', 'Invalid status selected.');
            return;
        }

        if (! array_key_exists($this->channel, $this->channelOptions)) {
            $this->addError('channel', 'Invalid channel selected.');
            return;
        }

        if ($this->channel === 'email' && ! $this->includeOwner && trim($this->recipients) === '') {
            $this->addError('recipients', 'Provide at least one recipient or include the project owner.');
            return;
        }

        if ($this->channel === 'webhook' && trim($this->webhookUrl) === '') {
            $this->addError('webhookUrl', 'Webhook URL is required.');
            return;
        }

        $workflow = $this->editingId
            ? Workflow::findOrFail($this->editingId)
            : new Workflow();

        $workflow->name = $this->name;
        $workflow->action = $this->action;
        $workflow->status = $this->status;
        $workflow->channel = $this->channel;
        $workflow->enabled = $this->enabled;
        $workflow->include_owner = $this->channel === 'email' ? $this->includeOwner : false;
        $workflow->recipients = $this->channel === 'email' ? $this->recipients : null;
        $workflow->webhook_url = $this->channel === 'webhook' ? $this->webhookUrl : null;
        if ($this->channel === 'webhook') {
            if ($this->webhookSecret !== '') {
                $workflow->webhook_secret = $this->webhookSecret;
            }
        } else {
            $workflow->webhook_secret = null;
        }

        $workflow->save();

        $label = $this->editingId ? 'Workflow updated.' : 'Workflow created.';
        $this->resetForm();
        $this->tab = 'list';
        $this->dispatch('notify', message: $label);
    }

    public function sendTestEmail(SettingsService $settings): void
    {
        $this->validate([
            'testEmail' => ['required', 'email'],
        ]);

        $settings->applyMailConfig();

        Mail::raw('Git Web Manager workflow test email.', function ($message) {
            $message->to($this->testEmail)
                ->subject('Git Web Manager workflow test');
        });

        $this->dispatch('notify', message: 'Test email sent.');
    }

    public function sendTestWebhook(): void
    {
        if ($this->testWebhookUrl === '') {
            $this->testWebhookUrl = $this->webhookUrl;
        }

        $this->validate([
            'testWebhookUrl' => ['required', 'url'],
        ]);

        Http::timeout(8)->post($this->testWebhookUrl, [
            'event' => 'workflow.test',
            'message' => 'Git Web Manager test webhook',
            'timestamp' => now()->toDateTimeString(),
        ]);

        $this->dispatch('notify', message: 'Test webhook sent.');
    }

    public function startEdit(int $workflowId): void
    {
        $workflow = Workflow::findOrFail($workflowId);

        $this->editingId = $workflow->id;
        $this->name = $workflow->name;
        $this->action = $workflow->action;
        $this->status = $workflow->status;
        $this->channel = $workflow->channel;
        $this->enabled = $workflow->enabled;
        $this->includeOwner = $workflow->include_owner;
        $this->recipients = (string) ($workflow->recipients ?? '');
        $this->webhookUrl = (string) ($workflow->webhook_url ?? '');
        $this->webhookSecret = '';
        $this->tab = 'form';
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
        $this->tab = 'list';
    }

    public function deleteWorkflow(int $workflowId): void
    {
        Workflow::query()->whereKey($workflowId)->delete();
        $this->dispatch('notify', message: 'Workflow deleted.');
    }

    public function toggleWorkflow(int $workflowId): void
    {
        $workflow = Workflow::findOrFail($workflowId);
        $workflow->enabled = ! $workflow->enabled;
        $workflow->save();
    }

    public function formatActionLabel(string $action): string
    {
        return Str::headline($action);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'form' ? 'form' : 'list';
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->action = 'deploy';
        $this->status = 'success';
        $this->channel = 'email';
        $this->enabled = true;
        $this->includeOwner = true;
        $this->recipients = '';
        $this->webhookUrl = '';
        $this->webhookSecret = '';
    }
}
