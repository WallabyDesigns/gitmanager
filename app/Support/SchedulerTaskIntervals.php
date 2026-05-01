<?php

namespace App\Support;

class SchedulerTaskIntervals
{
    public const SETTINGS_KEY = 'system.scheduler.task_intervals';

    /**
     * @return array<string, array{
     *   label: string,
     *   description: string,
     *   default_value: int,
     *   default_unit: string,
     *   anchor: string
     * }>
     */
    public static function definitions(): array
    {
        return [
            'project_health_checks' => [
                'label' => 'Project Polling & Health Checks',
                'description' => 'Checks auto-deploy projects for new commits and runs health checks for deployed apps.',
                'default_value' => 5,
                'default_unit' => 'minutes',
                'anchor' => '00:00',
            ],
            'queue_processing' => [
                'label' => 'Queue Processing',
                'description' => 'Processes queued deployments, audits, and maintenance tasks.',
                'default_value' => 1,
                'default_unit' => 'minutes',
                'anchor' => '00:00',
            ],
            'self_audit' => [
                'label' => 'System Audit',
                'description' => 'Runs panel dependency and vulnerability checks.',
                'default_value' => 10,
                'default_unit' => 'minutes',
                'anchor' => '00:00',
            ],
            'self_update' => [
                'label' => 'System Self Update',
                'description' => 'Runs the panel self-updater when auto-updates are enabled.',
                'default_value' => 24,
                'default_unit' => 'hours',
                'anchor' => '02:30',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function unitOptions(): array
    {
        return [
            'minutes' => 'Min',
            'hours' => 'Hrs',
        ];
    }

    /**
     * @return array<string, array{value: int, unit: string}>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::definitions() as $task => $definition) {
            $defaults[$task] = [
                'value' => (int) $definition['default_value'],
                'unit' => (string) $definition['default_unit'],
            ];
        }

        return $defaults;
    }

    /**
     * @return array<string, array{value: int, unit: string}>
     */
    public static function normalize(mixed $raw): array
    {
        $normalized = self::defaults();
        if (! is_array($raw)) {
            return $normalized;
        }

        foreach (self::definitions() as $task => $definition) {
            $entry = $raw[$task] ?? null;
            if (! is_array($entry)) {
                continue;
            }

            $unit = self::normalizeUnit($entry['unit'] ?? null, (string) $definition['default_unit']);
            $normalized[$task] = [
                'value' => self::normalizeValue($entry['value'] ?? null, $unit, (int) $definition['default_value']),
                'unit' => $unit,
            ];
        }

        return $normalized;
    }

    public static function normalizeUnit(mixed $unit, string $default = 'minutes'): string
    {
        $unit = strtolower(trim((string) $unit));

        return array_key_exists($unit, self::unitOptions()) ? $unit : $default;
    }

    public static function normalizeValue(mixed $value, string $unit, int $default): int
    {
        $value = (int) $value;
        if ($value < 1) {
            $value = $default;
        }

        $max = $unit === 'hours' ? 24 : 59;

        return min($value, $max);
    }

    /**
     * @param  array{value?: mixed, unit?: mixed}  $interval
     */
    public static function cronExpression(array $interval, string $anchor = '00:00'): string
    {
        $hour = self::anchorHour($anchor);
        $minute = self::anchorMinute($anchor);
        $unit = self::normalizeUnit($interval['unit'] ?? null);
        $value = self::normalizeValue($interval['value'] ?? null, $unit, $unit === 'hours' ? 1 : 1);

        if ($unit === 'hours') {
            if ($value >= 24) {
                return sprintf('%d %d * * *', $minute, $hour);
            }

            if ($hour === 0) {
                return sprintf('%d */%d * * *', $minute, $value);
            }

            return sprintf('%d %d-23/%d * * *', $minute, $hour, $value);
        }

        return sprintf('*/%d * * * *', $value);
    }

    private static function anchorHour(string $anchor): int
    {
        [$hour] = self::parseAnchor($anchor);

        return $hour;
    }

    private static function anchorMinute(string $anchor): int
    {
        [, $minute] = self::parseAnchor($anchor);

        return $minute;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseAnchor(string $anchor): array
    {
        $parts = array_pad(array_map('trim', explode(':', $anchor, 2)), 2, '0');
        $hour = max(0, min(23, (int) $parts[0]));
        $minute = max(0, min(59, (int) $parts[1]));

        return [$hour, $minute];
    }
}
