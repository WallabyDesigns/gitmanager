<?php

namespace App\Services;

class EmailBrandingService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{name: string, logo_url: string}
     */
    public function resolve(): array
    {
        $isEnterprise = strtolower((string) $this->settings->get('system.license.edition', 'community')) === EditionService::ENTERPRISE;
        $defaultName = 'Git Web Manager';
        $defaultLogo = url('images/email-logo.png');

        if (! $isEnterprise) {
            return ['name' => $defaultName, 'logo_url' => $defaultLogo];
        }

        $name = trim((string) $this->settings->get('system.white_label.name', ''));
        $logo = trim((string) $this->settings->get('system.white_label.logo_url', ''));

        return [
            'name' => $name ?: $defaultName,
            'logo_url' => $this->emailSafeLogoUrl($logo, $defaultLogo),
        ];
    }

    private function absoluteUrl(string $value): string
    {
        if (Str::startsWith($value, ['http://', 'https://', 'data:'])) {
            return $value;
        }

        return url('/'.ltrim($value, '/'));
    }

    private function emailSafeLogoUrl(?string $logo, string $defaultLogo): string
    {
        $url = trim((string) $logo) === '' ? $defaultLogo : $this->absoluteUrl((string) $logo);
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_ends_with($path, '.svg') ? $defaultLogo : $url;
    }
}
