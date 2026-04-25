<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\DeploymentService;
use App\Services\FtpService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class FtpAuditManifestRefreshTest extends TestCase
{
    private string $workspace;

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function test_ftp_manifest_refresh_removes_stale_local_files_when_remote_file_is_missing(): void
    {
        $this->workspace = storage_path('framework/testing/ftp-refresh-'.uniqid());
        File::ensureDirectoryExists($this->workspace);
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'composer.lock', '{"stale":true}');

        $project = new Project([
            'ftp_enabled' => true,
            'ssh_enabled' => false,
        ]);

        $ftp = Mockery::mock(FtpService::class);
        $ftp->shouldReceive('fetchRemoteFiles')
            ->once()
            ->with(Mockery::on(fn ($value) => $value === $project), ['composer.lock'], Mockery::type('array'))
            ->andReturn(['composer.lock' => null]);
        $this->app->instance(FtpService::class, $ftp);

        $output = [];
        $service = app(DeploymentService::class);
        $method = new \ReflectionMethod($service, 'refreshFtpManifestFiles');
        $method->setAccessible(true);
        $method->invokeArgs($service, [$project, $this->workspace, ['composer.lock'], &$output, true]);

        $this->assertFileDoesNotExist($this->workspace.DIRECTORY_SEPARATOR.'composer.lock');
        $this->assertContains('FTP manifest sync: removed stale composer.lock.', $output);
    }
}
