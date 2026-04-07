<?php

namespace App\Livewire\System;

use App\Models\User;
use App\Services\SettingsService;
use Livewire\Component;

class Settings extends Component
{
    public bool $checkUpdates = true;
    public bool $autoUpdate = true;
    public bool $githubSslVerify = true;
    public string $timezone = '';
    public array $timezones = [];

    public function mount(SettingsService $settings): void
    {
        $this->checkUpdates = (bool) ($settings->get('system.check_updates', true));
        $this->autoUpdate = (bool) ($settings->get('system.auto_update', (bool) config('gitmanager.self_update.enabled', true)));
        $this->githubSslVerify = (bool) ($settings->get(
            'system.github_ssl_verify',
            (bool) config('services.github.verify_ssl', true)
        ));

        $this->timezones = \DateTimeZone::listIdentifiers();
        $stored = (string) ($settings->get('system.timezone') ?? '');
        if ($stored === '') {
            $stored = (string) (User::query()->where('id', 1)->value('timezone') ?? '');
        }
        if ($stored === '') {
            $stored = (string) config('app.timezone');
        }
        $this->timezone = $stored;
    }

    public function render()
    {
        return view('livewire.system.settings')
            ->layout('layouts.app', [
                'title' => 'System Settings',
                'header' => view('livewire.system.partials.header', [
                    'title' => 'System',
                    'subtitle' => 'Manage app updates, security checks, settings, and email.',
                ]),
            ]);
    }

    public function save(SettingsService $settings): void
    {
        $this->validate([
            'timezone' => ['required', \Illuminate\Validation\Rule::in($this->timezones)],
        ]);

        $settings->set('system.check_updates', $this->checkUpdates);
        $settings->set('system.auto_update', $this->autoUpdate);
        $settings->set('system.github_ssl_verify', $this->githubSslVerify);
        $settings->set('system.timezone', $this->timezone);

        if (User::query()->where('id', 1)->exists()) {
            User::query()->where('id', 1)->update(['timezone' => $this->timezone]);
        }

        $this->dispatch('notify', message: 'System settings saved.');
        $this->dispatch('settings-saved');
    }
}
