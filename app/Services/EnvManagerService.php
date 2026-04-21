<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Artisan;

class EnvManagerService
{
    private string $envPath;
    private string $examplePath;

    public function __construct()
    {
        $this->envPath = base_path('.env');
        $this->examplePath = base_path('.env.example');
    }

    /**
     * Parse a .env file into key => value pairs.
     * Strips surrounding quotes from values.
     *
     * @return array<string, string>
     */
    public function parseFileToKv(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($lines)) {
            return [];
        }

        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $eqPos = strpos($line, '=');

            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[-1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Parse .env.example to get all known keys with their default values and
     * inline/preceding comment as a description.
     *
     * @return array<string, array{key: string, default: string, description: string}>
     */
    public function getExampleKeys(): array
    {
        if (! is_file($this->examplePath)) {
            return [];
        }

        $lines = file($this->examplePath, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines)) {
            return [];
        }

        $result = [];
        $pendingComment = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $pendingComment = '';
                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                $comment = ltrim(substr($trimmed, 1));
                $pendingComment = $pendingComment !== '' ? $pendingComment.' '.$comment : $comment;
                continue;
            }

            $eqPos = strpos($trimmed, '=');

            if ($eqPos === false) {
                $pendingComment = '';
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));
            $default = trim(substr($trimmed, $eqPos + 1));

            if ($key !== '') {
                $result[$key] = [
                    'key' => $key,
                    'default' => $default,
                    'description' => $pendingComment,
                ];
            }

            $pendingComment = '';
        }

        return $result;
    }

    /**
     * Get keys that are in .env.example but absent from the current .env.
     *
     * @return array<string, array{key: string, default: string, description: string}>
     */
    public function getMissingKeys(): array
    {
        $exampleKeys = $this->getExampleKeys();
        $current = $this->parseFileToKv($this->envPath);

        $missing = [];

        foreach ($exampleKeys as $key => $meta) {
            if (! array_key_exists($key, $current)) {
                $missing[$key] = $meta;
            }
        }

        return $missing;
    }

    /**
     * Keys hidden from the Environment Config UI — either unused or developer-only.
     */
    public const HIDDEN_KEYS = [
        'GWM_ENTERPRISE_BUY_URL',
        'GWM_EDITION',
        'GWM_EDITION_TESTING_UNLOCK_HASHES',
    ];

    /**
     * Get all GWM_* keys from the current .env with their values, descriptions, and defaults.
     *
     * @return array<string, array{key: string, value: string, description: string, default: string}>
     */
    public function getGwmKeys(): array
    {
        $current = $this->parseFileToKv($this->envPath);
        $example = $this->getExampleKeys();
        $result = [];

        foreach ($current as $key => $value) {
            if (str_starts_with($key, 'GWM_') && ! in_array($key, self::HIDDEN_KEYS, true)) {
                $result[$key] = [
                    'key' => $key,
                    'value' => $value,
                    'description' => $example[$key]['description'] ?? '',
                    'default' => $example[$key]['default'] ?? '',
                ];
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * Set a single key in the .env file.
     */
    public function set(string $key, string $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * Set multiple key-value pairs in the .env file atomically.
     * Existing keys are updated in-place; new keys are appended.
     *
     * @param array<string, string> $values
     */
    public function setMany(array $values): void
    {
        if (! is_file($this->envPath)) {
            return;
        }

        $content = file_get_contents($this->envPath);

        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);
        $handled = [];

        foreach ($lines as &$line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $eqPos = strpos($trimmed, '=');

            if ($eqPos === false) {
                continue;
            }

            $lineKey = trim(substr($trimmed, 0, $eqPos));

            if (array_key_exists($lineKey, $values)) {
                $line = $lineKey.'='.$this->formatValue((string) $values[$lineKey]);
                $handled[$lineKey] = true;
            }
        }

        unset($line);

        foreach ($values as $key => $value) {
            if (! isset($handled[$key])) {
                $lines[] = $key.'='.$this->formatValue((string) $value);
            }
        }

        $this->writeAtomic($this->envPath, implode("\n", $lines));
        $this->clearConfigCache();
    }

    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (str_contains($value, ' ') || str_contains($value, '"') || str_contains($value, '#')) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }

    private function writeAtomic(string $path, string $content): void
    {
        $tmp = $path.'.tmp.'.uniqid('', true);
        file_put_contents($tmp, $content, LOCK_EX);
        rename($tmp, $path);
    }

    private function clearConfigCache(): void
    {
        try {
            Artisan::call('config:clear');
        } catch (\Throwable) {
        }
    }
}
