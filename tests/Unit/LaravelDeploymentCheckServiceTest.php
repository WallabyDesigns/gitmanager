<?php

namespace Tests\Unit;

use App\Services\LaravelDeploymentCheckService;
use App\Services\PermissionService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LaravelDeploymentCheckServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = storage_path('framework/testing/laravel-checks-'.uniqid());
        File::makeDirectory($this->workspace, 0775, true, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function test_artisan_storage_link_requires_vendor_autoload(): void
    {
        $root = $this->workspace;
        File::put($root.DIRECTORY_SEPARATOR.'artisan', '<?php');

        $service = new LaravelDeploymentCheckService(new PermissionService());
        $method = new \ReflectionMethod($service, 'canRunArtisan');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, $root));

        File::makeDirectory($root.DIRECTORY_SEPARATOR.'vendor', 0775, true, true);
        File::put($root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php', '<?php');

        $this->assertTrue($method->invoke($service, $root));
    }

    public function test_windows_junction_paths_are_normalized(): void
    {
        $service = new LaravelDeploymentCheckService(new PermissionService());
        $method = new \ReflectionMethod($service, 'windowsPath');
        $method->setAccessible(true);

        $this->assertSame(
            'E:\\vsprojects\\gitmanager\\storage\\app\\ftp-workspaces\\1\\public\\storage',
            $method->invoke($service, 'E:\\vsprojects\\gitmanager\\storage\\app/ftp-workspaces\\1/public/storage')
        );
    }

    public function test_storage_symlink_is_valid_when_target_exists(): void
    {
        $root = $this->workspace;
        $linkPath = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage';
        $targetPath = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';

        File::makeDirectory($targetPath, 0775, true, true);
        File::makeDirectory(dirname($linkPath), 0775, true, true);
        if (! @symlink($targetPath, $linkPath)) {
            $this->markTestSkipped('The filesystem does not allow creating symlinks here.');
        }

        $service = new LaravelDeploymentCheckService(new PermissionService());
        $method = new \ReflectionMethod($service, 'storageLinkIsValid');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, $linkPath, $targetPath));
    }
}
