<?php

namespace App\Support;

class SupportMessageView
{
    /**
     * @param  array<string, mixed>  $entry
     * @return array{
     *     original_html: string,
     *     translated_html: string,
     *     has_translation: bool,
     *     source_locale: string,
     *     source_label: string,
     *     target_locale: string,
     *     target_label: string
     * }
     */
    public static function fromEntry(array $entry, ?string $targetLocale = null): array
    {
        $targetLocale = LanguageOptions::normalize($targetLocale ?? app()->getLocale());
        $sourceLocale = LanguageOptions::normalize(
            (string) ($entry['source_locale'] ?? $entry['locale'] ?? $entry['original_locale'] ?? 'en')
        );

        $originalHtml = self::html(
            $entry['message_html'] ?? null,
            $entry['message'] ?? ''
        );

        $translatedHtml = self::html(
            $entry['translated_message_html']
                ?? $entry['message_translated_html']
                ?? $entry['translation_html']
                ?? null,
            $entry['translated_message']
                ?? $entry['message_translated']
                ?? $entry['translation']
                ?? ''
        );

        $hasTranslation = $translatedHtml !== ''
            && trim(strip_tags($translatedHtml)) !== trim(strip_tags($originalHtml))
            && $sourceLocale !== $targetLocale;

        return [
            'original_html' => $originalHtml,
            'translated_html' => $hasTranslation ? $translatedHtml : $originalHtml,
            'has_translation' => $hasTranslation,
            'source_locale' => $sourceLocale,
            'source_label' => LanguageOptions::englishLabel($sourceLocale),
            'target_locale' => $targetLocale,
            'target_label' => LanguageOptions::englishLabel($targetLocale),
        ];
    }

    private static function html(mixed $html, mixed $plain): string
    {
        $html = trim((string) $html);
        if ($html !== '') {
            return $html;
        }

        return nl2br(e((string) $plain));
    }
}
