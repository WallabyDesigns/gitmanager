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

    public function test_it_reports_when_reverb_credentials_are_configured(): void
    {
        config()->set('broadcasting.connections.reverb.key', 'key');
        config()->set('broadcasting.connections.reverb.secret', 'secret');
        config()->set('broadcasting.connections.reverb.app_id', 'app-id');
        config()->set('broadcasting.connections.reverb.options.host', 'example.test');

        $status = app(RuntimePluginStatusService::class)->reverb();

        $this->assertTrue($status['credentials_configured']);
    }

    public function test_it_reports_an_unavailable_configured_rust_executor(): void
    {
        config()->set('gitmanager.rust_executor.binary', 'missing-gwm-rust-executor');

        $status = app(RuntimePluginStatusService::class)->rustExecutor();

        $this->assertFalse($status['installed']);
        $this->assertSame('missing-gwm-rust-executor', $status['binary']);
    }
}
