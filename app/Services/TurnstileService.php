<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function isEnabled(SettingsService $settings): bool
    {
        return (bool) $settings->get('system.captcha.enabled', false)
            && trim((string) $settings->get('system.captcha.site_key', '')) !== ''
            && trim((string) $settings->getDecrypted('system.captcha.secret_key', '')) !== '';
    }

    public function siteKey(SettingsService $settings): string
    {
        return trim((string) $settings->get('system.captcha.site_key', ''));
    }

    public function verify(string $token, SettingsService $settings): bool
    {
        if (! $this->isEnabled($settings)) {
            return true;
        }
        if ($token === '') {
            return false;
        }
        $secret = trim((string) $settings->getDecrypted('system.captcha.secret_key', ''));
        if ($secret === '') {
            return false;
        }
        try {
            $response = Http::timeout(10)->asForm()->post(self::VERIFY_URL, [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => request()->ip(),
            ]);

            return (bool) ($response->json('success') ?? false);
        } catch (\Throwable) {
            return false;
        }
    }
}
