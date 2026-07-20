<?php

namespace Tests\Unit;

use App\Services\NodeInstallService;
use App\Services\Plugins\NodeRuntimePlugin;
use Tests\TestCase;

class NodeRuntimePluginTest extends TestCase
{
    public function test_update_installs_the_latest_lts_version(): void
    {
        $installer = $this->createMock(NodeInstallService::class);
        $installer->expects($this->once())
            ->method('install')
            ->with('v24.18.0')
            ->willReturn(['success' => true, 'message' => 'updated']);

        $plugin = new class($installer) extends NodeRuntimePlugin
        {
            public function fetchLatestVersion(): ?string
            {
                return 'v24.18.0';
            }
        };

        $this->assertSame(['success' => true, 'message' => 'updated'], $plugin->update());
    }

    public function test_update_fails_when_the_latest_version_cannot_be_determined(): void
    {
        $installer = $this->createMock(NodeInstallService::class);
        $installer->expects($this->never())->method('install');

        $plugin = new class($installer) extends NodeRuntimePlugin
        {
            public function fetchLatestVersion(): ?string
            {
                return null;
            }
        };

        $result = $plugin->update();

        $this->assertFalse($result['success']);
        $this->assertSame(__('Could not determine the latest Node.js LTS version.'), $result['message']);
    }
}
