<?php

namespace Tests\Unit;

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
}
