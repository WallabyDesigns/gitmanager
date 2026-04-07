<?php

namespace App\Support;

use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;

class DateFormatter
{
    private static ?array $validTimezones = null;
    private static bool $adminTimezoneResolved = false;
    private static ?string $adminTimezone = null;

    public static function forUser(DateTimeInterface|string|null $value, string $format, string $fallback = '—'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value, config('app.timezone'));

        $timezone = self::resolveTimezone();
        if ($timezone !== null) {
            $date = $date->copy()->setTimezone($timezone);
        }

        return $date->format($format);
    }

    private static function resolveTimezone(): ?string
    {
        $timezone = null;

        try {
            $timezone = app(SettingsService::class)->get('system.timezone');
        } catch (\Throwable $exception) {
            $timezone = null;
        }

        if (! $timezone) {
            $timezone = Auth::user()?->timezone;
        }
        if (! $timezone) {
            $timezone = self::resolveAdminTimezone();
        }

        if (! $timezone) {
            return config('app.timezone');
        }

        if (self::$validTimezones === null) {
            self::$validTimezones = array_flip(timezone_identifiers_list());
        }

        return isset(self::$validTimezones[$timezone]) ? $timezone : config('app.timezone');
    }

    private static function resolveAdminTimezone(): ?string
    {
        if (self::$adminTimezoneResolved) {
            return self::$adminTimezone;
        }

        self::$adminTimezoneResolved = true;
        self::$adminTimezone = User::query()->where('id', 1)->value('timezone');

        return self::$adminTimezone;
    }
}
