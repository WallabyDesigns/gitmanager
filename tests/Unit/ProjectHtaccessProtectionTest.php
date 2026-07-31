<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectHtaccessProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_projects_get_a_protective_htaccess_written(): void
    {
        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'laravel',
        ]);

        $path = storage_path('framework/testing/htaccess-laravel-'.uniqid());
        mkdir($path, 0775, true);

        try {
            $output = [];
            $service = app(DeploymentService::class);
            $method = new \ReflectionMethod($service, 'ensureProjectHtaccess');
            $method->setAccessible(true);
            $method->invokeArgs($service, [$project, $path, &$output]);

            $htaccessPath = $path.DIRECTORY_SEPARATOR.'.htaccess';
            $this->assertFileExists($htaccessPath);

            $contents = file_get_contents($htaccessPath);
            $this->assertStringContainsString('\\.env', $contents);
            $this->assertStringContainsString('storage(/.*)?', $contents);
        } finally {
            $this->removeDirectory($path);
        }
    }

    public function test_existing_project_htaccess_is_not_overwritten(): void
    {
        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'laravel',
        ]);

        $path = storage_path('framework/testing/htaccess-existing-'.uniqid());
        mkdir($path, 0775, true);
        $htaccessPath = $path.DIRECTORY_SEPARATOR.'.htaccess';
        file_put_contents($htaccessPath, "# custom user config\n");

        try {
            $output = [];
            $service = app(DeploymentService::class);
            $method = new \ReflectionMethod($service, 'ensureProjectHtaccess');
            $method->setAccessible(true);
            $method->invokeArgs($service, [$project, $path, &$output]);

            $this->assertSame("# custom user config\n", file_get_contents($htaccessPath));
        } finally {
            $this->removeDirectory($path);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($entryPath)) {
                $this->removeDirectory($entryPath);
            } else {
                unlink($entryPath);
            }
        }

        rmdir($path);
    }
}
