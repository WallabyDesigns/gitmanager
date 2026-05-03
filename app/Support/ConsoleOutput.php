<?php

namespace App\Support;

class ConsoleOutput
{
    public static function withoutPhpWarnings(?string $output): ?string
    {
        if ($output === null || $output === '') {
            return $output;
        }

        $lines = preg_split('/\r\n|\r|\n/', $output);
        if ($lines === false) {
            return $output;
        }

        $filtered = array_values(array_filter($lines, static function (string $line): bool {
            return ! preg_match('/^\s*PHP\s+Warning:/i', $line);
        }));

        return trim(implode("\n", $filtered));
    }
}
