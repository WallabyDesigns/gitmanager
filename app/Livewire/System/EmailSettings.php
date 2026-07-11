<?php

namespace App\Livewire\System;

use App\Mail\SystemNotificationMail;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class EmailSettings extends Component
{
    public string $mailer = 'smtp';

    public string $host = '';

    public string $port = '';

    public string $username = '';

    public string $password = '';

    public string $encryption = '';

    public string $fromAddress = '';

    public string $fromName = '';

    public string $testRecipient = '';

    public function mount(SettingsService $settings): void
    {
        $this->mailer = (string) ($settings->get('mail.mailer') ?? config('mail.default', 'smtp'));
        $this->host = (string) ($settings->get('mail.host') ?? config('mail.mailers.smtp.host', ''));
        $this->port = (string) ($settings->get('mail.port') ?? config('mail.mailers.smtp.port', ''));
        $this->username = (string) ($settings->get('mail.username') ?? config('mail.mailers.smtp.username', ''));
        $this->encryption = (string) ($settings->get('mail.encryption') ?? config('mail.mailers.smtp.encryption', ''));
        $this->fromAddress = (string) ($settings->get('mail.from_address') ?? config('mail.from.address', ''));
        $this->fromName = (string) ($settings->get('mail.from_name') ?? config('mail.from.name', 'Git Web Manager'));
    }

    public function render()
    {
        return view('livewire.system.email-settings')
            ->layout('layouts.app', [
                'title' => 'Email Settings',
                'header' => view('livewire.system.partials.header', [
                    'title' => 'System',
                    'subtitle' => 'Manage app updates, security checks, settings, and email.',
                ]),
            ]);
    }

    public function save(SettingsService $settings): void
    {
        $this->validate([
            'mailer' => ['required', 'string'],
            'host' => ['nullable', 'string'],
            'port' => ['nullable', 'string'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'encryption' => ['nullable', 'string'],
            'fromAddress' => ['required', 'email'],
            'fromName' => ['required', 'string'],
        ]);

        $settings->set('mail.mailer', $this->mailer);
        $settings->set('mail.host', $this->host);
        $settings->set('mail.port', $this->port);
        $settings->set('mail.username', $this->username);
        $settings->set('mail.encryption', $this->encryption);
        $settings->set('mail.from_address', $this->fromAddress);
        $settings->set('mail.from_name', $this->fromName);

        if ($this->password !== '') {
            $settings->setEncrypted('mail.password', $this->password);
            $this->password = '';
        }

        $settings->applyMailConfig();
        $this->dispatch('notify', message: 'Email settings saved.');
    }

    public function sendTest(SettingsService $settings): void
    {
        $this->validate([
            'testRecipient' => ['required', 'email'],
        ]);

        $settings->applyMailConfig();

        Mail::to($this->testRecipient)->send(new SystemNotificationMail(
            __('Git Web Manager test email'),
            __('Email delivery is working'),
            __('This test message confirms that your email settings can deliver a styled Git Web Manager notification.'),
        ));

        $this->dispatch('notify', message: 'Test email sent.');
    }
}
