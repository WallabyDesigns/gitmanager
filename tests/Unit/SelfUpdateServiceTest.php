<?php

namespace Tests\Unit;

use App\Services\GitHubService;
use App\Services\SelfUpdateService;
use Illuminate\Support\Facades\File;
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
        $this->assertContains('Enterprise package: composer binary not available, skipping.', $output);
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
