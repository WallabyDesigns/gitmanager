<?php

namespace App\Livewire\System;

use App\Services\EditionService;
use App\Services\SettingsService;
use Livewire\Component;

class WhiteLabel extends Component
{
    public bool $isEnterprise = false;
    public string $whiteLabelName = '';
    public string $whiteLabelLogoUrl = '';
    public string $whiteLabelFaviconUrl = '';

    public function mount(EditionService $edition, SettingsService $settings): void
    {
        $this->isEnterprise = $edition->current() === EditionService::ENTERPRISE;
        $this->whiteLabelName = (string) ($settings->get('system.white_label.name', ''));
        $this->whiteLabelLogoUrl = (string) ($settings->get('system.white_label.logo_url', ''));
        $this->whiteLabelFaviconUrl = (string) ($settings->get('system.white_label.favicon_url', ''));
    }

    public function render(EditionService $edition): \Illuminate\View\View
    {
        $this->isEnterprise = $edition->current() === EditionService::ENTERPRISE;

        return view('livewire.system.white-label')
            ->layout('layouts.app', [
                'title' => 'White Label',
                'header' => view('livewire.system.partials.header', [
                    'title' => 'System',
                    'subtitle' => 'Manage app updates, security checks, settings, and email.',
                ]),
            ]);
    }

    public function save(EditionService $edition, SettingsService $settings): void
    {
        if ($edition->current() !== EditionService::ENTERPRISE) {
            $this->isEnterprise = false;
            $this->dispatch('gwm-open-enterprise-modal', feature: 'White Label Branding');
            return;
        }

        $httpsUrl = function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== '' && ! str_starts_with(strtolower((string) $value), 'https://')) {
                $fail("The {$attribute} must use HTTPS.");
            }
        };

        $this->validate([
            'whiteLabelName' => ['nullable', 'string', 'max:255'],
            'whiteLabelLogoUrl' => ['nullable', 'url', 'max:2048', $httpsUrl],
            'whiteLabelFaviconUrl' => ['nullable', 'url', 'max:2048', $httpsUrl],
        ]);

        $settings->set('system.white_label.name', trim($this->whiteLabelName));
        $settings->set('system.white_label.logo_url', trim($this->whiteLabelLogoUrl));
        $settings->set('system.white_label.favicon_url', trim($this->whiteLabelFaviconUrl));

        $this->dispatch('notify', message: 'White label settings saved.');
        $this->dispatch('white-label-saved');
        $this->dispatch('reload-page', delay: 700);
    }
}
