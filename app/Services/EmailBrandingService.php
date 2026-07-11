<?php

namespace App\Services;

use Illuminate\Support\Str;

class EmailBrandingService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{name: string, logo_url: string}
     */
    public function resolve(): array
    {
        $values = $this->settings->all();
        $isEnterprise = strtolower((string) ($values['system.license.edition'] ?? 'community')) === EditionService::ENTERPRISE;
        $defaultName = 'Git Web Manager';
        $defaultLogo = url('images/email-logo.png');

        if (! $isEnterprise || $this->whiteLabelIsDisabled($values)) {
            return ['name' => $defaultName, 'logo_url' => $defaultLogo];
        }

        $name = $this->firstString($values, [
            'white_label.name',
            'white_label.app_name',
            'white_label.brand_name',
            'system.white_label.name',
            'system.white_label.app_name',
            'system.white_label.brand_name',
        ]) ?? $this->findWhiteLabelValue($values, ['name', 'app_name', 'brand_name']);

        $logo = $this->firstString($values, [
            'white_label.logo_url',
            'white_label.logo',
            'white_label.logo_path',
            'system.white_label.logo_url',
            'system.white_label.logo',
            'system.white_label.logo_path',
        ]) ?? $this->findWhiteLabelValue($values, ['logo_url', 'logo', 'logo_path']);

        return [
            'name' => $name ?: $defaultName,
            'logo_url' => $this->emailSafeLogoUrl($logo, $defaultLogo),
        ];
    }

    /** @param array<string, mixed> $values */
    private function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($values[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $values */
    private function findWhiteLabelValue(array $values, array $suffixes): ?string
    {
        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (! str_contains($normalizedKey, 'white') || ! str_contains($normalizedKey, 'label')) {
                continue;
            }

            if (! in_array(Str::afterLast($normalizedKey, '.'), $suffixes, true)) {
                continue;
            }

            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
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

    /** @param array<string, mixed> $values */
    private function whiteLabelIsDisabled(array $values): bool
    {
        foreach (['white_label.enabled', 'system.white_label.enabled'] as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            return filter_var($values[$key], FILTER_VALIDATE_BOOLEAN) === false;
        }

        return false;
    }
}
