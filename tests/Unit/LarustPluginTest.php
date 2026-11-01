<?php

namespace Tests\Unit;

use App\Services\Plugins\LarustPlugin;
use Illuminate\Support\Facades\Http;
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

    public function test_latest_version_comes_from_crates_io(): void
    {
        Http::fake(['crates.io/*' => Http::response(['crate' => ['max_stable_version' => '0.11.0']])]);

        $this->assertSame('0.11.0', (new LarustPlugin)->fetchLatestVersion());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'crates.io/api/v1/crates/larust-cli'));
    }

    public function test_latest_version_is_null_when_crates_io_fails_or_returns_garbage(): void
    {
        Http::fake(['crates.io/*' => Http::response(['crate' => ['max_stable_version' => 'not-a-version']])]);
        $this->assertNull((new LarustPlugin)->fetchLatestVersion());

        Http::fake(['crates.io/*' => Http::response('', 500)]);
        $this->assertNull((new LarustPlugin)->fetchLatestVersion());
    }

    public function test_legacy_git_install_is_reported_as_git_revision_so_it_shows_as_outdated(): void
    {
        $dir = storage_path('plugins/larust');
        $binary = $dir.'/bin/'.(PHP_OS_FAMILY === 'Windows' ? 'xr.exe' : 'xr');
        @mkdir(dirname($binary), 0755, true);
        file_put_contents($binary, '');
        @chmod($binary, 0755);
        file_put_contents($dir.'/revision', str_repeat('a', 40));
        @unlink($dir.'/version');

        try {
            $plugin = new LarustPlugin;
            if (! $plugin->isInstalled()) {
                $this->markTestSkipped('Cannot mark a fake binary executable on this platform.');
            }

            $this->assertSame('git-aaaaaaa', $plugin->installedVersion());

            file_put_contents($dir.'/version', '0.11.0');
            $this->assertSame('0.11.0', $plugin->installedVersion());
        } finally {
            @unlink($dir.'/revision');
            @unlink($dir.'/version');
            @unlink($binary);
            @rmdir(dirname($binary));
            @rmdir($dir);
        }
    }
}
