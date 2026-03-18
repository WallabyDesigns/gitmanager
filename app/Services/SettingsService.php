<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class SettingsService
{
    private const CACHE_KEY = 'gwm_settings_cache';

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureTable();

        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => is_string($value) ? $value : json_encode($value)]
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys): array
    {
        $settings = $this->all();
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $settings)) {
                $result[$key] = $settings[$key];
            }
        }

        return $result;
    }

    public function setEncrypted(string $key, string $value): void
    {
        $this->set($key, Crypt::encryptString($value));
    }

    public function getDecrypted(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key);
        if (! is_string($value) || $value === '') {
            return $default;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $exception) {
            return $default;
        }
    }

    public function applyMailConfig(): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $values = $this->getMany([
            'mail.mailer',
            'mail.host',
            'mail.port',
            'mail.username',
            'mail.encryption',
            'mail.from_address',
            'mail.from_name',
        ]);

        $password = $this->getDecrypted('mail.password');

        if ($values !== []) {
            config([
                'mail.default' => $values['mail.mailer'] ?? config('mail.default'),
                'mail.mailers.smtp.host' => $values['mail.host'] ?? config('mail.mailers.smtp.host'),
                'mail.mailers.smtp.port' => $values['mail.port'] ?? config('mail.mailers.smtp.port'),
                'mail.mailers.smtp.username' => $values['mail.username'] ?? config('mail.mailers.smtp.username'),
                'mail.mailers.smtp.encryption' => $values['mail.encryption'] ?? config('mail.mailers.smtp.encryption'),
                'mail.mailers.smtp.password' => $password ?? config('mail.mailers.smtp.password'),
                'mail.from.address' => $values['mail.from_address'] ?? config('mail.from.address'),
                'mail.from.name' => $values['mail.from_name'] ?? config('mail.from.name'),
            ]);
        }
    }

    public function isMailConfigured(): bool
    {
        $this->applyMailConfig();

        $mailer = (string) (config('mail.default') ?? config('mail.mailer') ?? '');
        $from = (string) (config('mail.from.address') ?? '');

        if ($mailer === '' || in_array($mailer, ['log', 'array', 'null'], true)) {
            return false;
        }

        if ($from === '') {
            return false;
        }

        if ($mailer === 'smtp') {
            $host = (string) (config('mail.mailers.smtp.host') ?? '');
            $port = (string) (config('mail.mailers.smtp.port') ?? '');

            if ($host === '' || $port === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function () {
            return Setting::query()
                ->get()
                ->mapWithKeys(function (Setting $setting) {
                    $value = $setting->value;
                    if ($value === null) {
                        return [$setting->key => null];
                    }

                    $decoded = json_decode($value, true);
                    return [$setting->key => json_last_error() === JSON_ERROR_NONE ? $decoded : $value];
                })
                ->toArray();
        });
    }

    private function ensureTable(): void
    {
        if (! $this->tableExists()) {
            throw new \RuntimeException('Settings table does not exist. Run migrations to enable settings.');
        }
    }

    private function tableExists(): bool
    {
        return Schema::hasTable('settings');
    }
}
