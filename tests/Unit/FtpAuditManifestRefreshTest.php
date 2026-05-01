<?php

namespace Tests\Unit;

use App\Models\FtpAccount;
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
        $project = new Project([
            'ftp_enabled' => true,
            'ssh_enabled' => false,
        ]);
        $project->id = random_int(10000, 99999);
        $this->workspace = storage_path('framework/testing/ftp-workspaces'.DIRECTORY_SEPARATOR.$project->id);
        config(['gitmanager.ftp.workspace_path' => storage_path('framework/testing/ftp-workspaces')]);
        File::ensureDirectoryExists($this->workspace);
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'composer.lock', '{"stale":true}');

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

    public function test_ftp_manifest_refresh_clears_local_file_before_downloading_remote_copy(): void
    {
        $project = new Project([
            'ftp_enabled' => true,
            'ssh_enabled' => false,
        ]);
        $project->id = random_int(10000, 99999);
        $this->workspace = storage_path('framework/testing/ftp-workspaces'.DIRECTORY_SEPARATOR.$project->id);
        config(['gitmanager.ftp.workspace_path' => storage_path('framework/testing/ftp-workspaces')]);
        File::ensureDirectoryExists($this->workspace);
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'composer.lock', '{"old":true}');

        $ftp = Mockery::mock(FtpService::class);
        $ftp->shouldReceive('fetchRemoteFiles')
            ->once()
            ->with(Mockery::on(fn ($value) => $value === $project), ['composer.lock'], Mockery::type('array'))
            ->andReturn(['composer.lock' => '{"fresh":true}']);
        $this->app->instance(FtpService::class, $ftp);

        $output = [];
        $service = app(DeploymentService::class);
        $method = new \ReflectionMethod($service, 'refreshFtpManifestFiles');
        $method->setAccessible(true);
        $method->invokeArgs($service, [$project, $this->workspace, ['composer.lock'], &$output, true, true]);

        $this->assertSame('{"fresh":true}', file_get_contents($this->workspace.DIRECTORY_SEPARATOR.'composer.lock'));
        $this->assertContains('FTP manifest sync: cleared local composer.lock before remote refresh.', $output);
        $this->assertContains('FTP manifest sync: downloaded composer.lock.', $output);
    }

    public function test_ftp_manifest_refresh_does_not_reuse_local_file_when_remote_refresh_fails(): void
    {
        $project = new Project([
            'ftp_enabled' => true,
            'ssh_enabled' => false,
        ]);
        $project->id = random_int(10000, 99999);
        $this->workspace = storage_path('framework/testing/ftp-workspaces'.DIRECTORY_SEPARATOR.$project->id);
        config(['gitmanager.ftp.workspace_path' => storage_path('framework/testing/ftp-workspaces')]);
        File::ensureDirectoryExists($this->workspace);
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'composer.lock', '{"stale":true}');

        $ftp = Mockery::mock(FtpService::class);
        $ftp->shouldReceive('fetchRemoteFiles')
            ->once()
            ->with(Mockery::on(fn ($value) => $value === $project), ['composer.lock'], Mockery::type('array'))
            ->andReturn([]);
        $this->app->instance(FtpService::class, $ftp);

        $output = [];
        $service = app(DeploymentService::class);
        $method = new \ReflectionMethod($service, 'refreshFtpManifestFiles');
        $method->setAccessible(true);
        $result = $method->invokeArgs($service, [$project, $this->workspace, ['composer.lock'], &$output, true, true]);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($this->workspace.DIRECTORY_SEPARATOR.'composer.lock');
        $this->assertContains('FTP manifest sync: remote refresh returned no files; stale local manifests remain cleared.', $output);
    }

    public function test_ftp_resolved_root_does_not_duplicate_project_path_when_it_already_contains_base_root(): void
    {
        $project = new Project([
            'ftp_enabled' => true,
            'ssh_enabled' => false,
            'local_path' => '/admin.3dfinancial.org',
            'ftp_root_path' => null,
        ]);
        $project->setRelation('ftpAccount', new FtpAccount([
            'root_path' => '/admin.3dfinancial.org',
        ]));

        $this->assertSame('/admin.3dfinancial.org', app(FtpService::class)->resolvedRootPath($project));
    }

    public function test_ftp_resolved_root_combines_base_root_and_relative_project_path(): void
    {
        $project = new Project([
            'ftp_enabled' => true,
            'ssh_enabled' => false,
            'local_path' => 'current',
            'ftp_root_path' => '/domains/example.com',
        ]);

        $this->assertSame('/domains/example.com/current', app(FtpService::class)->resolvedRootPath($project));
    }

    public function test_ftp_sync_directory_paths_are_made_relative_to_sync_root(): void
    {
        $service = app(FtpService::class);
        $context = new \ReflectionProperty($service, 'syncContext');
        $context->setAccessible(true);
        $context->setValue($service, [
            'host' => 'example.com',
            'port' => 21,
            'username' => 'user',
            'password' => 'secret',
            'ssl' => true,
            'passive' => true,
            'timeout' => 30,
            'rootPath' => '/admin.3dfinancial.org',
        ]);

        $method = new \ReflectionMethod($service, 'relativeToSyncRoot');
        $method->setAccessible(true);

        $this->assertSame('app/Livewire/Users', $method->invoke($service, '/admin.3dfinancial.org/app/Livewire/Users'));
        $this->assertSame('app/Livewire/Users', $method->invoke($service, 'app/Livewire/Users'));
    }

    public function test_default_ftp_excludes_skip_laravel_public_storage_link(): void
    {
        $service = app(DeploymentService::class);
        $project = new Project;

        $excludeMethod = new \ReflectionMethod($service, 'ftpExcludePaths');
        $excludeMethod->setAccessible(true);
        $excludePaths = $excludeMethod->invoke($service, $project);

        $matchesMethod = new \ReflectionMethod($service, 'ftpPathMatchesAny');
        $matchesMethod->setAccessible(true);

        $this->assertContains('public/storage', $excludePaths);
        $this->assertTrue($matchesMethod->invoke($service, 'public/storage', $excludePaths));
        $this->assertTrue($matchesMethod->invoke($service, 'public/storage/file.txt', $excludePaths));
    }

    public function test_default_ftp_excludes_skip_sqlite_database_files(): void
    {
        $service = app(DeploymentService::class);
        $project = new Project;

        $excludeMethod = new \ReflectionMethod($service, 'ftpExcludePaths');
        $excludeMethod->setAccessible(true);
        $excludePaths = $excludeMethod->invoke($service, $project);

        $matchesMethod = new \ReflectionMethod($service, 'ftpPathMatchesAny');
        $matchesMethod->setAccessible(true);

        $this->assertTrue($matchesMethod->invoke($service, 'database/database.sqlite', $excludePaths));
        $this->assertTrue($matchesMethod->invoke($service, 'database/database.sqlite-wal', $excludePaths));
        $this->assertTrue($matchesMethod->invoke($service, 'database/app.db', $excludePaths));
        $this->assertTrue($matchesMethod->invoke($service, 'database/app.db-shm', $excludePaths));
    }

    public function test_fresh_sqlite_deploy_can_include_sqlite_database_files(): void
    {
        $service = app(DeploymentService::class);
        $project = new Project;

        $excludeMethod = new \ReflectionMethod($service, 'ftpExcludePaths');
        $excludeMethod->setAccessible(true);
        $excludePaths = $excludeMethod->invoke($service, $project, [], true);

        $matchesMethod = new \ReflectionMethod($service, 'ftpPathMatchesAny');
        $matchesMethod->setAccessible(true);

        $this->assertFalse($matchesMethod->invoke($service, 'database/database.sqlite', $excludePaths));
        $this->assertFalse($matchesMethod->invoke($service, 'database/app.db', $excludePaths));
        $this->assertTrue($matchesMethod->invoke($service, 'public/storage/file.txt', $excludePaths));
    }

    public function test_ftp_only_deployments_skip_local_laravel_migrations_after_initial_deploy(): void
    {
        $this->workspace = storage_path('framework/testing/ftp-migrate-skip-'.uniqid());
        File::ensureDirectoryExists($this->workspace);
        File::put($this->workspace.DIRECTORY_SEPARATOR.'artisan', '<?php');

        $project = new Project([
            'ftp_enabled' => true,
            'ssh_enabled' => false,
            'local_path' => $this->workspace,
        ]);

        $output = [];
        $service = app(DeploymentService::class);
        $method = new \ReflectionMethod($service, 'maybeRunLaravelMigrations');
        $method->setAccessible(true);
        $method->invokeArgs($service, [$project, $this->workspace, &$output]);

        $this->assertContains('Skipping Laravel migrate: FTP-only deployments cannot run migrations on the remote host after initial deployment.', $output);
    }
}
