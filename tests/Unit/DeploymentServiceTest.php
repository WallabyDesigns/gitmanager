<?php

namespace Tests\Unit;

use App\Services\DeploymentService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = storage_path('framework/testing/deployment-service-'.uniqid());
        File::makeDirectory($this->workspace, 0775, true, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function test_delete_directory_removes_windows_junctions_without_deleting_target(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows junction cleanup is Windows-specific.');
        }

        $root = $this->workspace.DIRECTORY_SEPARATOR.'root';
        $target = $this->workspace.DIRECTORY_SEPARATOR.'target';
        $link = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage';

        File::makeDirectory($target, 0775, true, true);
        File::makeDirectory(dirname($link), 0775, true, true);

        $process = new Process([
            'cmd',
            '/c',
            'mklink',
            '/J',
            str_replace('/', '\\', $link),
            str_replace('/', '\\', $target),
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('Unable to create Windows junction for cleanup test.');
        }

        $service = app(DeploymentService::class);
        $method = new \ReflectionMethod($service, 'deleteDirectory');
        $method->setAccessible(true);
        $method->invoke($service, $root);

        $this->assertDirectoryDoesNotExist($root);
        $this->assertDirectoryExists($target);
    }
}
