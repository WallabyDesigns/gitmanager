<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\DeploymentService;
use App\Services\FtpService;
use App\Services\LaravelDeploymentCheckService;
use App\Services\PermissionService;
use App\Services\ProjectSeedService;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FtpStagingEnvironmentTest extends TestCase
{
    private string $directory;

    private string $workspace;

    private string $stage;

    private string $seed;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gwm-ftp-env-'.bin2hex(random_bytes(8));
        $this->workspace = $this->directory.DIRECTORY_SEPARATOR.'repo';
        $this->stage = $this->directory.DIRECTORY_SEPARATOR.'stage';
        $this->seed = $this->directory.DIRECTORY_SEPARATOR.'setup.env';
        File::ensureDirectoryExists($this->workspace);
        File::ensureDirectoryExists($this->stage);
        $this->project = new Project([
            'ftp_enabled' => true, 'ssh_enabled' => false, 'project_type' => 'laravel',
            'run_composer_install' => false, 'run_npm_install' => false,
            'run_build_command' => false, 'run_test_command' => false,
        ]);
        $this->project->id = random_int(10000, 99999);
        $seeds = Mockery::mock(ProjectSeedService::class);
        $seeds->shouldReceive('seedPath')->with($this->project, '.env')->andReturn($this->seed);
        $this->app->instance(ProjectSeedService::class, $seeds);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function prepare(): array
    {
        $output = [];
        $method = new \ReflectionMethod(DeploymentService::class, 'prepareFtpStagingEnvironment');
        $method->invokeArgs(app(DeploymentService::class), [$this->project, $this->workspace, $this->stage, &$output]);

        return $output;
    }

    private function ftp(): MockInterface
    {
        $ftp = Mockery::mock(FtpService::class);
        $this->app->instance(FtpService::class, $ftp);

        return $ftp;
    }

    public function test_missing_workspace_env_is_recovered_and_copied_without_logging_contents(): void
    {
        $secret = "APP_KEY=fake-test-secret\nDB_PASSWORD=fake-db-secret\r\n";
        $this->ftp()->shouldReceive('fetchRemoteFiles')->once()
            ->with($this->project, ['.env'], Mockery::type('array'))->andReturn(['.env' => $secret]);
        $output = $this->prepare();
        $this->assertSame($secret, file_get_contents($this->workspace.DIRECTORY_SEPARATOR.'.env'));
        $this->assertSame($secret, file_get_contents($this->stage.DIRECTORY_SEPARATOR.'.env'));
        $this->assertContains('Imported remote .env into FTP workspace.', $output);
        $this->assertStringNotContainsString('fake-test-secret', implode("\n", $output));
        $this->assertStringNotContainsString('fake-db-secret', implode("\n", $output));
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($this->workspace.DIRECTORY_SEPARATOR.'.env') & 0777);
            $this->assertSame(0600, fileperms($this->stage.DIRECTORY_SEPARATOR.'.env') & 0777);
        }
    }

    public function test_existing_workspace_and_stage_files_win_without_remote_requests(): void
    {
        $this->ftp()->shouldNotReceive('fetchRemoteFiles');
        File::put($this->workspace.DIRECTORY_SEPARATOR.'.env', 'LOCAL=keep');
        $this->prepare();
        $this->assertSame('LOCAL=keep', file_get_contents($this->stage.DIRECTORY_SEPARATOR.'.env'));
        File::put($this->stage.DIRECTORY_SEPARATOR.'.env', 'STAGED=keep');
        $this->prepare();
        $this->assertSame('LOCAL=keep', file_get_contents($this->workspace.DIRECTORY_SEPARATOR.'.env'));
        $this->assertSame('STAGED=keep', file_get_contents($this->stage.DIRECTORY_SEPARATOR.'.env'));
    }

    public function test_explicit_setup_seed_takes_precedence_over_remote_config(): void
    {
        $this->ftp()->shouldNotReceive('fetchRemoteFiles');
        File::put($this->seed, 'SETUP=chosen');
        $this->prepare();
        $this->assertSame('SETUP=chosen', file_get_contents($this->workspace.DIRECTORY_SEPARATOR.'.env'));
        $this->assertSame('SETUP=chosen', file_get_contents($this->stage.DIRECTORY_SEPARATOR.'.env'));
        $this->assertFileDoesNotExist($this->seed);
    }

    public function test_missing_or_empty_remote_config_still_fails_normal_env_validation(): void
    {
        $ftp = $this->ftp();
        $ftp->shouldReceive('fetchRemoteFiles')->times(3)->andReturn([], ['.env' => null], ['.env' => " \r\n"]);
        foreach (range(1, 3) as $case) {
            $this->prepare();
            $this->assertFileDoesNotExist($this->workspace.DIRECTORY_SEPARATOR.'.env');
            $this->assertFileDoesNotExist($this->stage.DIRECTORY_SEPARATOR.'.env');
            $check = new LaravelDeploymentCheckService(new PermissionService);
            $output = [];
            try {
                (new \ReflectionMethod($check, 'ensureLaravelEnvFile'))->invokeArgs($check, [$this->stage, &$output]);
                $this->fail('Missing environment must not bypass validation.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('.env file missing', $exception->getMessage());
            }
        }
    }

    public function test_remote_failure_does_not_log_exception_secrets_or_create_a_fake_env(): void
    {
        $this->ftp()->shouldReceive('fetchRemoteFiles')->once()->andThrow(new \RuntimeException('password=fake-private-value'));
        $output = $this->prepare();
        $this->assertContains('FTP seed import skipped: remote files could not be retrieved.', $output);
        $this->assertStringNotContainsString('fake-private-value', implode("\n", $output));
        $this->assertFileDoesNotExist($this->stage.DIRECTORY_SEPARATOR.'.env');
    }

    public function test_a_local_file_created_during_download_is_not_overwritten(): void
    {
        $this->ftp()->shouldReceive('fetchRemoteFiles')->once()->andReturnUsing(function () {
            File::put($this->workspace.DIRECTORY_SEPARATOR.'.env', 'RACE=local');

            return ['.env' => 'REMOTE=do-not-overwrite'];
        });
        $this->prepare();
        $this->assertSame('RACE=local', file_get_contents($this->workspace.DIRECTORY_SEPARATOR.'.env'));
        $this->assertSame('RACE=local', file_get_contents($this->stage.DIRECTORY_SEPARATOR.'.env'));
    }

    public function test_empty_files_directories_and_local_or_ssh_projects_are_not_replaced(): void
    {
        $this->ftp()->shouldNotReceive('fetchRemoteFiles');
        File::put($this->workspace.DIRECTORY_SEPARATOR.'.env', '');
        $this->prepare();
        $this->assertSame('', file_get_contents($this->workspace.DIRECTORY_SEPARATOR.'.env'));
        $this->assertFileDoesNotExist($this->stage.DIRECTORY_SEPARATOR.'.env');
        File::delete($this->workspace.DIRECTORY_SEPARATOR.'.env');
        File::ensureDirectoryExists($this->workspace.DIRECTORY_SEPARATOR.'.env');
        $this->prepare();
        $this->assertDirectoryExists($this->workspace.DIRECTORY_SEPARATOR.'.env');
        File::deleteDirectory($this->workspace.DIRECTORY_SEPARATOR.'.env');
        File::put($this->workspace.DIRECTORY_SEPARATOR.'.env', 'LOCAL=not-ftp');
        foreach ([[false, false], [true, true]] as [$ftp, $ssh]) {
            $this->project->ftp_enabled = $ftp;
            $this->project->ssh_enabled = $ssh;
            $this->assertSame([], $this->prepare());
            $this->assertFileDoesNotExist($this->stage.DIRECTORY_SEPARATOR.'.env');
        }
    }

    public function test_dangling_symlink_is_not_replaced_or_followed(): void
    {
        if (! @symlink($this->directory.DIRECTORY_SEPARATOR.'absent', $this->workspace.DIRECTORY_SEPARATOR.'.env')) {
            $this->markTestSkipped('This account cannot create symlinks.');
        }
        $this->ftp()->shouldNotReceive('fetchRemoteFiles');
        $this->prepare();
        $this->assertTrue(is_link($this->workspace.DIRECTORY_SEPARATOR.'.env'));
        $this->assertFileDoesNotExist($this->stage.DIRECTORY_SEPARATOR.'.env');
        unlink($this->workspace.DIRECTORY_SEPARATOR.'.env');
    }

    public function test_real_worktree_prepares_env_before_checks_and_cleans_it_on_failure(): void
    {
        $this->git(['init', '--initial-branch=main']);
        File::put($this->workspace.DIRECTORY_SEPARATOR.'artisan', 'fixture, never executed');
        File::put($this->workspace.DIRECTORY_SEPARATOR.'.gitignore', ".env\n");
        $this->git(['add', '.']);
        $this->git(['-c', 'user.name=GWM fixture', '-c', 'user.email=fixture@example.test', 'commit', '-m', 'fixture']);
        $hash = trim($this->git(['rev-parse', 'HEAD']));
        $this->ftp()->shouldReceive('fetchRemoteFiles')->once()->andReturn(['.env' => 'REMOTE=staging-test']);
        $base = $this->directory.DIRECTORY_SEPARATOR.'worktrees';
        config(['gitmanager.deploy_staging.enabled' => true, 'gitmanager.deploy_staging.path' => $base]);
        foreach ([false, true] as $fail) {
            $check = Mockery::mock(LaravelDeploymentCheckService::class);
            $check->shouldReceive('run')->times($fail ? 2 : 1)->andReturnUsing(function ($project, $path, &$output, $requireEnv) use ($fail) {
                $this->assertTrue($requireEnv);
                $this->assertSame('REMOTE=staging-test', file_get_contents($path.DIRECTORY_SEPARATOR.'.env'));
                if ($fail) {
                    throw new \RuntimeException('fixture validation failure');
                }
            });
            $this->app->instance(LaravelDeploymentCheckService::class, $check);
            $this->app->forgetInstance(DeploymentService::class);
            $output = [];
            try {
                (new \ReflectionMethod(DeploymentService::class, 'runStagingChecks'))->invokeArgs(app(DeploymentService::class), [$this->project, $this->workspace, $hash, &$output]);
                $this->assertFalse($fail);
            } catch (\RuntimeException $exception) {
                $this->assertTrue($fail);
                $this->assertSame('fixture validation failure', $exception->getMessage());
            }
            $this->assertSame([], File::allFiles($base));
            $this->assertSame('REMOTE=staging-test', file_get_contents($this->workspace.DIRECTORY_SEPARATOR.'.env'));
            $this->assertStringNotContainsString('REMOTE=staging-test', implode("\n", $output));
        }
    }

    private function git(array $args): string
    {
        $process = new Process(array_merge(['git', '-C', $this->workspace], $args));
        $process->mustRun();

        return $process->getOutput();
    }
}
