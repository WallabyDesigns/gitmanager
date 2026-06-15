<?php

namespace App\Livewire\System;

use App\Services\EditionService;
use App\Services\NavigationStateService;
use App\Services\SettingsService;
use Illuminate\View\View;
use Livewire\Component;

class WhiteLabel extends Component
{
    public bool $isEnterprise = false;

    public string $whiteLabelName = '';

    public string $whiteLabelLogoUrl = '';

    public string $whiteLabelFaviconUrl = '';

    public bool $hideEditionLabel = false;

    public string $whiteLabelSubHeading = '';

    public function mount(EditionService $edition, SettingsService $settings): void
    {
        $this->isEnterprise = $edition->current() === EditionService::ENTERPRISE;
        $this->whiteLabelName = (string) ($settings->get('system.white_label.name', ''));
        $this->whiteLabelLogoUrl = (string) ($settings->get('system.white_label.logo_url', ''));
        $this->whiteLabelFaviconUrl = (string) ($settings->get('system.white_label.favicon_url', ''));
        $this->hideEditionLabel = (bool) $settings->get('system.white_label.hide_edition_label', false);
        $this->whiteLabelSubHeading = (string) ($settings->get('system.white_label.sub_heading', ''));
    }

    public function render(EditionService $edition): View
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

    public function save(EditionService $edition, SettingsService $settings, NavigationStateService $navigationState): void
    {
        if ($edition->current() !== EditionService::ENTERPRISE) {
            $this->isEnterprise = false;
            $this->dispatch('gwm-open-enterprise-modal', feature: 'White Label Branding');

            return;
        }

        $validUrl = function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === '') {
                return;
            }
            $lower = strtolower((string) $value);
            $isAbsoluteUrl = str_starts_with($lower, 'https://') || str_starts_with($lower, 'http://');
            $isLocalPath = str_starts_with($value, '/') || str_starts_with($value, './') || str_starts_with($value, '../');
            if (! $isAbsoluteUrl && ! $isLocalPath) {
                $fail("The {$attribute} must be a URL (https://) or a local path (/).");
            }
        };

        $this->validate([
            'whiteLabelName' => ['nullable', 'string', 'max:255'],
            'whiteLabelLogoUrl' => ['nullable', 'string', 'max:2048', $validUrl],
            'whiteLabelFaviconUrl' => ['nullable', 'string', 'max:2048', $validUrl],
            'hideEditionLabel' => ['boolean'],
            'whiteLabelSubHeading' => ['nullable', 'string', 'max:100'],
        ]);

        $settings->set('system.white_label.name', trim($this->whiteLabelName));
        $settings->set('system.white_label.logo_url', trim($this->whiteLabelLogoUrl));
        $settings->set('system.white_label.favicon_url', trim($this->whiteLabelFaviconUrl));
        $settings->set('system.white_label.hide_edition_label', $this->hideEditionLabel);
        $settings->set('system.white_label.sub_heading', trim($this->whiteLabelSubHeading));

        $navigationState->flushBadges(auth()->user());

        $this->dispatch('notify', message: 'White label settings saved.');
        $this->dispatch('white-label-saved');
        $this->dispatch('reload-page', delay: 700);
    }
}
