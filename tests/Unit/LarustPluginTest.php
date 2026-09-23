<?php

namespace Tests\Unit;

use App\Services\Plugins\LarustPlugin;
use Tests\TestCase;

class LarustPluginTest extends TestCase
{
    public function test_plugin_installer_includes_the_managers_configured_process_path(): void
    {
        config()->set('gitmanager.process_path', '/srv/rust/bin');

        $environment = (new LarustPlugin)->environment(['PATH' => '/usr/bin']);

        $this->assertStringContainsString('/srv/rust/bin', $environment['PATH']);
        $this->assertStringEndsWith('/usr/bin', $environment['PATH']);
    }
}
