<?php

namespace Tests\Unit;

use App\Services\RuntimeServiceService;
use Tests\TestCase;

class RuntimeServiceServiceTest extends TestCase
{
    public function test_it_uses_larusts_documented_systemd_unit_name(): void
    {
        $this->assertSame('larust-Larust_Dashboard.service', RuntimeServiceService::unitName('Larust Dashboard'));
        $this->assertSame('larust-my_app.service', RuntimeServiceService::unitName('my-app'));
    }

    public function test_it_parses_running_systemd_status(): void
    {
        $status = RuntimeServiceService::parseStatus('larust-blog.service', "LoadState=loaded\nActiveState=active\nSubState=running\nResult=success\nMainPID=412");

        $this->assertTrue($status['installed']);
        $this->assertTrue($status['running']);
        $this->assertSame('running', $status['state']);
        $this->assertStringContainsString('running', $status['message']);
    }

    public function test_it_reports_an_uninstalled_unit_with_actionable_guidance(): void
    {
        $status = RuntimeServiceService::parseStatus('larust-blog.service', "LoadState=not-found\nActiveState=inactive\nSubState=dead");

        $this->assertFalse($status['installed']);
        $this->assertFalse($status['running']);
        $this->assertSame('not_installed', $status['state']);
        $this->assertStringContainsString('xr deploy --service', $status['message']);
    }
}
