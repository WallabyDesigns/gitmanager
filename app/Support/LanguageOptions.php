<?php

namespace App\Support;

class LanguageOptions
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'it' => 'Italiano',
            'pt_BR' => 'Português (Brasil)',
            'nl' => 'Nederlands',
            'pl' => 'Polski',
            'sv' => 'Svenska',
            'ja' => '日本語',
            'ko' => '한국어',
            'zh_CN' => '简体中文',
            'hi' => 'हिन्दी',
            'ar' => 'العربية',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function englishLabels(): array
    {
        return [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt_BR' => 'Portuguese (Brazil)',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'sv' => 'Swedish',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh_CN' => 'Chinese (Simplified)',
            'hi' => 'Hindi',
            'ar' => 'Arabic',
        ];
    }

    public static function englishLabel(?string $locale): string
    {
        $locale = self::normalize($locale);

        return self::englishLabels()[$locale] ?? self::englishLabels()['en'];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function default(): string
    {
        $configured = (string) config('app.locale', 'en');

        return self::normalize($configured);
    }

    public static function normalize(?string $locale): string
    {
        $locale = trim((string) $locale);

        return array_key_exists($locale, self::all()) ? $locale : 'en';
    }

    public static function label(?string $locale): string
    {
        $locale = self::normalize($locale);

        return self::all()[$locale] ?? self::all()['en'];
    }
}
