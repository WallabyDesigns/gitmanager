<?php

namespace App\Livewire\Workflows;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class Index extends Component
{
    public bool $emailEnabled = true;
    public bool $emailDeploySuccess = true;
    public bool $emailDeployFailed = true;
    public bool $emailIncludeOwner = true;
    public string $emailRecipients = '';

    public bool $webhookEnabled = false;
    public bool $webhookDeploySuccess = true;
    public bool $webhookDeployFailed = true;
    public string $webhookUrl = '';
    public string $webhookSecret = '';

    public string $testWebhookUrl = '';
    public string $testEmail = '';

    public function mount(SettingsService $settings): void
    {
        $this->emailEnabled = (bool) $settings->get('workflows.email.enabled', true);
        $this->emailDeploySuccess = (bool) $settings->get('workflows.email.events.deploy_success', true);
        $this->emailDeployFailed = (bool) $settings->get('workflows.email.events.deploy_failed', true);
        $this->emailIncludeOwner = (bool) $settings->get('workflows.email.include_project_owner', true);
        $this->emailRecipients = (string) $settings->get('workflows.email.recipients', '');

        $this->webhookEnabled = (bool) $settings->get('workflows.webhook.enabled', false);
        $this->webhookDeploySuccess = (bool) $settings->get('workflows.webhook.events.deploy_success', true);
        $this->webhookDeployFailed = (bool) $settings->get('workflows.webhook.events.deploy_failed', true);
        $this->webhookUrl = (string) $settings->get('workflows.webhook.url', '');
        $this->webhookSecret = (string) $settings->getDecrypted('workflows.webhook.secret', '');
    }

    public function render()
    {
        return view('livewire.workflows.index')
            ->layout('layouts.app', [
                'header' => view('livewire.workflows.partials.header'),
            ]);
    }

    public function save(SettingsService $settings): void
    {
        $this->validate([
            'emailRecipients' => ['nullable', 'string'],
            'webhookUrl' => ['nullable', 'url'],
            'webhookSecret' => ['nullable', 'string'],
        ]);

        $settings->set('workflows.email.enabled', $this->emailEnabled);
        $settings->set('workflows.email.events.deploy_success', $this->emailDeploySuccess);
        $settings->set('workflows.email.events.deploy_failed', $this->emailDeployFailed);
        $settings->set('workflows.email.include_project_owner', $this->emailIncludeOwner);
        $settings->set('workflows.email.recipients', $this->emailRecipients);

        $settings->set('workflows.webhook.enabled', $this->webhookEnabled);
        $settings->set('workflows.webhook.events.deploy_success', $this->webhookDeploySuccess);
        $settings->set('workflows.webhook.events.deploy_failed', $this->webhookDeployFailed);
        $settings->set('workflows.webhook.url', $this->webhookUrl);

        if ($this->webhookSecret !== '') {
            $settings->setEncrypted('workflows.webhook.secret', $this->webhookSecret);
            $this->webhookSecret = '';
        }

        $this->dispatch('notify', message: 'Workflow settings saved.');
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
}
