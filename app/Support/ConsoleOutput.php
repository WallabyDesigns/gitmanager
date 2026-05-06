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

        $filtered = [];
        $skipSourceGuardianLines = 0;

        foreach ($lines as $line) {
            if ($skipSourceGuardianLines > 0) {
                $skipSourceGuardianLines--;

                continue;
            }

            if (preg_match('/^\s*PHP\s+Warning:/i', $line)) {
                continue;
            }

            if (preg_match('/^\s*SourceGuardian\s+requires\s+Zend\s+Engine\s+API\s+version\b/i', $line)) {
                $skipSourceGuardianLines = 2;

                continue;
            }

            $filtered[] = $line;
        }

        return trim(implode("\n", $filtered));
    }
}
