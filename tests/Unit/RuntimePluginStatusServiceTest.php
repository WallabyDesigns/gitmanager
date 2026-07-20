<?php

namespace Tests\Unit;

use App\Services\RuntimePluginStatusService;
use Tests\TestCase;

class RuntimePluginStatusServiceTest extends TestCase
{
    public function test_it_detects_the_bundled_reverb_package(): void
    {
        $status = app(RuntimePluginStatusService::class)->reverb();

        $this->assertTrue($status['installed']);
        $this->assertNotEmpty($status['version']);
        $this->assertFalse($status['configured']);
    }

    public function test_it_reports_an_unavailable_configured_rust_executor(): void
    {
        config()->set('gitmanager.rust_executor.binary', 'missing-gwm-rust-executor');

        $status = app(RuntimePluginStatusService::class)->rustExecutor();

        $this->assertFalse($status['installed']);
        $this->assertSame('missing-gwm-rust-executor', $status['binary']);
    }
}
