<?php

namespace Tests\Unit;

use App\Support\SchedulerTaskIntervals;
use Tests\TestCase;

class SchedulerTaskIntervalsTest extends TestCase
{
    public function test_normalize_uses_defaults_for_missing_or_invalid_entries(): void
    {
        $intervals = SchedulerTaskIntervals::normalize([
            'project_health_checks' => [
                'value' => 0,
                'unit' => 'days',
            ],
            'queue_processing' => [
                'value' => 61,
                'unit' => 'minutes',
            ],
        ]);

        $this->assertSame(5, $intervals['project_health_checks']['value']);
        $this->assertSame('minutes', $intervals['project_health_checks']['unit']);
        $this->assertSame(59, $intervals['queue_processing']['value']);
        $this->assertSame('minutes', $intervals['queue_processing']['unit']);
        $this->assertSame(24, $intervals['self_update']['value']);
        $this->assertSame('hours', $intervals['self_update']['unit']);
        $this->assertArrayNotHasKey('license_verification', $intervals);
    }

    public function test_license_verification_is_not_user_configurable(): void
    {
        $definitions = SchedulerTaskIntervals::definitions();

        $this->assertArrayNotHasKey('license_verification', $definitions);
    }

    public function test_cron_expression_supports_minute_and_hour_intervals(): void
    {
        $this->assertSame(
            '*/15 * * * *',
            SchedulerTaskIntervals::cronExpression(['value' => 15, 'unit' => 'minutes'])
        );

        $this->assertSame(
            '0 */3 * * *',
            SchedulerTaskIntervals::cronExpression(['value' => 3, 'unit' => 'hours'])
        );

        $this->assertSame(
            '30 2-23/6 * * *',
            SchedulerTaskIntervals::cronExpression(['value' => 6, 'unit' => 'hours'], '02:30')
        );

        $this->assertSame(
            '30 2 * * *',
            SchedulerTaskIntervals::cronExpression(['value' => 24, 'unit' => 'hours'], '02:30')
        );
    }
}
