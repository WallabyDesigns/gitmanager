<?php

namespace Tests\Unit;

use App\Models\AppUpdate;
use App\Services\GitHubService;
use App\Services\SelfUpdateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SelfUpdateServiceTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            File::deleteDirectory($path);
        }

        parent::tearDown();
    }

    public function test_get_enterprise_package_status_reports_missing_composer_binary_without_touching_output(): void
    {
        config([
            'gitmanager.enterprise.package_name' => 'wallabydesigns/gitmanager-enterprise',
            'gitmanager.composer_binary' => 'definitely-not-a-real-composer-binary',
        ]);

        $projectPath = $this->makeComposerProject(
            [
                'name' => 'test/app',
                'require' => [
                    'php' => '^8.2',
                    'wallabydesigns/gitmanager-enterprise' => '^1.0',
                ],
            ],
            [
                'packages' => [
                    [
                        'name' => 'wallabydesigns/gitmanager-enterprise',
                        'version' => '1.2.3',
                    ],
                ],
            ]
        );

        $output = [];
        $status = app(SelfUpdateService::class)->getEnterprisePackageStatus($projectPath, $output);

        $this->assertSame('unknown', $status['status']);
        $this->assertFalse($status['installed']);
        $this->assertNull($status['current']);
        $this->assertSame([], $output);
        $this->assertStringContainsString('Composer binary not available', $status['message']);
    }

    public function test_install_or_update_enterprise_package_never_writes_auth_json(): void
    {
        config([
            'gitmanager.enterprise.package_name' => 'wallabydesigns/gitmanager-enterprise',
            'gitmanager.composer_binary' => 'definitely-not-a-real-composer-binary',
        ]);

        $projectPath = $this->makeComposerProject([
            'name' => 'test/app',
            'require' => [
                'php' => '^8.2',
            ],
        ]);

        $output = [];
        app(SelfUpdateService::class)->installOrUpdateEnterprisePackage($projectPath, $output, [
            'name' => 'wallabydesigns/gitmanager-enterprise',
            'status' => 'not-installed',
        ]);

        $this->assertFileDoesNotExist($projectPath.DIRECTORY_SEPARATOR.'auth.json');
        $this->assertContains('System package: composer binary not available, skipping.', $output);
    }

    public function test_deployment_guard_blocks_failed_github_report(): void
    {
        config([
            'gitmanager.self_update.deployment_guard.enabled' => true,
            'gitmanager.self_update.deployment_guard.environment' => 'production',
        ]);

        $this->mock(GitHubService::class, function ($mock) {
            $mock->shouldReceive('resolveRepoFullNameFromUrl')
                ->once()
                ->andReturn('wallabydesigns/gitmanager');

            $mock->shouldReceive('getLatestDeploymentStatusForRef')
                ->once()
                ->with('wallabydesigns/gitmanager', 'abc1234', 'production')
                ->andReturn([
                    'deployment_id' => 42,
                    'ref' => 'abc1234',
                    'state' => 'failure',
                    'environment' => 'production',
                    'description' => 'Smoke tests failed.',
                    'created_at' => '2026-04-22T01:00:00Z',
                    'updated_at' => '2026-04-22T01:05:00Z',
                    'target_url' => 'https://github.com/wallabydesigns/gitmanager/deployments/42',
                    'log_url' => 'https://github.com/wallabydesigns/gitmanager/actions/runs/42',
                ]);
        });

        $guard = $this->deploymentGuard('abc1234');

        $this->assertSame('blocked', $guard['status']);
        $this->assertTrue($guard['checked']);
        $this->assertTrue($guard['blocked']);
        $this->assertSame('failure', $guard['state']);
        $this->assertSame('production', $guard['environment']);
        $this->assertSame('Smoke tests failed.', $guard['description']);
        $this->assertStringContainsString('on hold', $guard['message']);
    }

    public function test_deployment_guard_allows_successful_github_report(): void
    {
        config([
            'gitmanager.self_update.deployment_guard.enabled' => true,
            'gitmanager.self_update.deployment_guard.environment' => 'production',
        ]);

        $this->mock(GitHubService::class, function ($mock) {
            $mock->shouldReceive('resolveRepoFullNameFromUrl')
                ->once()
                ->andReturn('wallabydesigns/gitmanager');

            $mock->shouldReceive('getLatestDeploymentStatusForRef')
                ->once()
                ->with('wallabydesigns/gitmanager', 'def5678', 'production')
                ->andReturn([
                    'deployment_id' => 99,
                    'ref' => 'def5678',
                    'state' => 'success',
                    'environment' => 'production',
                    'description' => 'Deployment finished successfully.',
                    'created_at' => '2026-04-22T02:00:00Z',
                    'updated_at' => '2026-04-22T02:04:00Z',
                    'target_url' => 'https://github.com/wallabydesigns/gitmanager/deployments/99',
                    'log_url' => null,
                ]);
        });

        $guard = $this->deploymentGuard('def5678');

        $this->assertSame('healthy', $guard['status']);
        $this->assertTrue($guard['checked']);
        $this->assertFalse($guard['blocked']);
        $this->assertSame('success', $guard['state']);
        $this->assertStringContainsString('reported success', $guard['message']);
    }

    public function test_resolve_update_target_hash_limits_incremental_backlog(): void
    {
        config([
            'gitmanager.self_update.max_commits_per_run' => 1,
        ]);

        $service = new class extends SelfUpdateService
        {
            public int $pendingCount = 3;

            /** @var list<string> */
            public array $pendingHashes = ['bbb222'];

            public function inspectResolveUpdateTargetHash(string $repoPath, ?string $fromHash, string $toHash, array &$output): string
            {
                return $this->resolveUpdateTargetHash($repoPath, $fromHash, $toHash, $output);
            }

            protected function countPendingUpdateCommits(string $repoPath, string $fromHash, string $toHash): int
            {
                return $this->pendingCount;
            }

            protected function pendingUpdateCommits(string $repoPath, string $fromHash, string $toHash, int $maxCount): array
            {
                return array_slice($this->pendingHashes, 0, $maxCount);
            }
        };

        $output = [];
        $target = $service->inspectResolveUpdateTargetHash(base_path(), 'aaa111', 'ddd444', $output);

        $this->assertSame('bbb222', $target);
        $this->assertStringContainsString('Incremental self-update enabled', implode("\n", $output));
        $this->assertStringContainsString('2 commit(s) will remain', implode("\n", $output));
    }

    public function test_resolve_update_target_hash_uses_latest_when_batch_covers_backlog(): void
    {
        config([
            'gitmanager.self_update.max_commits_per_run' => 3,
        ]);

        $service = new class extends SelfUpdateService
        {
            public int $pendingCount = 2;

            /** @var list<string> */
            public array $pendingHashes = ['bbb222', 'ccc333'];

            public function inspectResolveUpdateTargetHash(string $repoPath, ?string $fromHash, string $toHash, array &$output): string
            {
                return $this->resolveUpdateTargetHash($repoPath, $fromHash, $toHash, $output);
            }

            protected function countPendingUpdateCommits(string $repoPath, string $fromHash, string $toHash): int
            {
                return $this->pendingCount;
            }

            protected function pendingUpdateCommits(string $repoPath, string $fromHash, string $toHash, int $maxCount): array
            {
                return array_slice($this->pendingHashes, 0, $maxCount);
            }
        };

        $output = [];
        $target = $service->inspectResolveUpdateTargetHash(base_path(), 'aaa111', 'ccc333', $output);

        $this->assertSame('ccc333', $target);
        $this->assertSame([], $output);
    }

    public function test_php_binary_prefers_live_env_override_over_cached_config(): void
    {
        config([
            'gitmanager.php_binary' => 'php-from-config',
        ]);

        $override = 'C:\\php\\php-custom.exe';
        $originalEnv = getenv('GWM_PHP_PATH');
        $originalServer = $_SERVER['GWM_PHP_PATH'] ?? null;
        $originalRequestEnv = $_ENV['GWM_PHP_PATH'] ?? null;

        putenv('GWM_PHP_PATH='.$override);
        $_SERVER['GWM_PHP_PATH'] = $override;
        $_ENV['GWM_PHP_PATH'] = $override;

        try {
            $service = app(SelfUpdateService::class);
            $method = new \ReflectionMethod($service, 'phpBinary');
            $method->setAccessible(true);

            $this->assertSame($override, $method->invoke($service));
        } finally {
            if ($originalEnv === false) {
                putenv('GWM_PHP_PATH');
            } else {
                putenv('GWM_PHP_PATH='.$originalEnv);
            }

            if ($originalServer === null) {
                unset($_SERVER['GWM_PHP_PATH']);
            } else {
                $_SERVER['GWM_PHP_PATH'] = $originalServer;
            }

            if ($originalRequestEnv === null) {
                unset($_ENV['GWM_PHP_PATH']);
            } else {
                $_ENV['GWM_PHP_PATH'] = $originalRequestEnv;
            }
        }
    }

    public function test_sync_enterprise_package_applies_available_update(): void
    {
        $service = new class extends SelfUpdateService
        {
            /** @var list<array<string, mixed>> */
            public array $statuses = [];

            public bool $installCalled = false;

            public function getEnterprisePackageStatus(string $repoPath, array &$output): array
            {
                return array_shift($this->statuses) ?? [];
            }

            public function installOrUpdateEnterprisePackage(string $repoPath, array &$output, array $enterprisePackage): void
            {
                $this->installCalled = true;
                $output[] = 'Updating enterprise package.';
            }

            /**
             * @return array<string, mixed>
             */
            public function inspectSync(string $repoPath, array &$output, bool $applyChanges): array
            {
                return $this->syncEnterprisePackage($repoPath, $output, $applyChanges);
            }
        };

        $service->statuses = [
            [
                'name' => 'wallabydesigns/gitmanager-enterprise',
                'status' => 'update-available',
                'current' => '1.0.0',
                'latest' => '1.0.1',
                'message' => 'Enterprise package update is available.',
            ],
            [
                'name' => 'wallabydesigns/gitmanager-enterprise',
                'status' => 'up-to-date',
                'current' => '1.0.1',
                'latest' => '1.0.1',
                'message' => 'Enterprise package is up to date.',
            ],
        ];

        $output = [];
        $result = $service->inspectSync(base_path(), $output, true);

        $this->assertTrue($service->installCalled);
        $this->assertTrue($result['performed']);
        $this->assertSame('update-available', $result['initial']['status']);
        $this->assertSame('up-to-date', $result['current']['status']);
    }

    public function test_sync_enterprise_package_skips_when_already_current(): void
    {
        $service = new class extends SelfUpdateService
        {
            /** @var list<array<string, mixed>> */
            public array $statuses = [];

            public bool $installCalled = false;

            public function getEnterprisePackageStatus(string $repoPath, array &$output): array
            {
                return array_shift($this->statuses) ?? [];
            }

            public function installOrUpdateEnterprisePackage(string $repoPath, array &$output, array $enterprisePackage): void
            {
                $this->installCalled = true;
            }

            /**
             * @return array<string, mixed>
             */
            public function inspectSync(string $repoPath, array &$output, bool $applyChanges): array
            {
                return $this->syncEnterprisePackage($repoPath, $output, $applyChanges);
            }
        };

        $service->statuses = [
            [
                'name' => 'wallabydesigns/gitmanager-enterprise',
                'status' => 'up-to-date',
                'current' => '1.0.1',
                'latest' => '1.0.1',
                'message' => 'Enterprise package is up to date.',
            ],
        ];

        $output = [];
        $result = $service->inspectSync(base_path(), $output, true);

        $this->assertFalse($service->installCalled);
        $this->assertFalse($result['performed']);
        $this->assertSame('up-to-date', $result['current']['status']);
    }

    public function test_resolve_rollback_target_ignores_non_applied_attempts(): void
    {
        if (! Schema::hasTable('app_updates')) {
            Schema::create('app_updates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('triggered_by')->nullable();
                $table->string('status')->default('running');
                $table->string('from_hash')->nullable();
                $table->string('to_hash')->nullable();
                $table->longText('output_log')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        AppUpdate::query()->create([
            'status' => 'success',
            'from_hash' => 'good111',
            'to_hash' => 'good222',
            'started_at' => now()->subMinutes(5),
        ]);

        AppUpdate::query()->create([
            'status' => 'warning',
            'from_hash' => 'good222',
            'to_hash' => 'good222',
            'started_at' => now()->subMinutes(4),
        ]);

        AppUpdate::query()->create([
            'status' => 'blocked',
            'from_hash' => null,
            'to_hash' => null,
            'started_at' => now()->subMinutes(3),
        ]);

        AppUpdate::query()->create([
            'status' => 'failed',
            'from_hash' => 'bad333',
            'to_hash' => 'bad444',
            'started_at' => now()->subMinutes(2),
        ]);

        $service = new class extends SelfUpdateService
        {
            public function inspectResolveRollbackTarget(?string $targetHash, array &$output): ?string
            {
                return $this->resolveRollbackTarget($targetHash, $output);
            }

            protected function isValidRollbackTarget(string $repoPath, string $target): bool
            {
                return true;
            }
        };

        $output = [];
        $target = $service->inspectResolveRollbackTarget(null, $output);

        $this->assertSame('good111', $target);
        $this->assertStringContainsString('Using last update rollback target: good111', implode("\n", $output));
    }

    public function test_validate_updated_application_rejects_invalid_compiled_views(): void
    {
        $projectPath = $this->makeFakeArtisanProject('invalid');

        $service = new class extends SelfUpdateService
        {
            public function inspectValidateUpdatedApplication(string $repoPath, array &$output): void
            {
                $this->validateUpdatedApplication($repoPath, $output);
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compiled view validation failed');

        $output = [];
        $service->inspectValidateUpdatedApplication($projectPath, $output);
    }

    /**
     * @param array<string, mixed> $composer
     * @param array<string, mixed>|null $lock
     */
    private function makeComposerProject(array $composer, ?array $lock = null): string
    {
        $path = storage_path('framework/testing/self-update-'.Str::uuid());
        File::ensureDirectoryExists($path);

        file_put_contents($path.DIRECTORY_SEPARATOR.'composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        if ($lock !== null) {
            file_put_contents($path.DIRECTORY_SEPARATOR.'composer.lock', json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->tempPaths[] = $path;

        return $path;
    }

    private function makeFakeArtisanProject(string $viewMode = 'valid'): string
    {
        $path = storage_path('framework/testing/self-update-artisan-'.Str::uuid());
        File::ensureDirectoryExists($path.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views');

        file_put_contents($path.DIRECTORY_SEPARATOR.'.view-mode', $viewMode);
        file_put_contents($path.DIRECTORY_SEPARATOR.'artisan', <<<'PHP'
<?php
$command = $argv[1] ?? '';
$viewsDir = __DIR__.'/storage/framework/views';
if (! is_dir($viewsDir)) {
    mkdir($viewsDir, 0775, true);
}

if ($command === 'view:clear') {
    foreach (glob($viewsDir.'/*.php') ?: [] as $file) {
        @unlink($file);
    }
    exit(0);
}

if ($command === 'view:cache') {
    $modeFile = __DIR__.'/.view-mode';
    $mode = is_file($modeFile) ? trim((string) file_get_contents($modeFile)) : 'valid';
    $content = $mode === 'invalid'
        ? "<?php if (\n"
        : "<?php echo 'cached';\n";
    file_put_contents($viewsDir.'/compiled.php', $content);
    echo "Views cached\n";
    exit(0);
}

if ($command === 'optimize:clear' || $command === 'app:clear-cache') {
    echo "Cleared\n";
    exit(0);
}

if ($command === 'list') {
    echo json_encode(['commands' => [['name' => 'app:clear-cache']]]);
    exit(0);
}

exit(0);
PHP
        );

        $this->tempPaths[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function deploymentGuard(string $hash): array
    {
        $service = new class extends SelfUpdateService
        {
            /**
             * @return array<string, mixed>
             */
            public function inspectDeploymentGuard(string $hash): array
            {
                return $this->resolveDeploymentGuard($hash);
            }
        };

        return $service->inspectDeploymentGuard($hash);
    }
}
