<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_page_requires_authentication(): void
    {
        $this->get('/recovery')->assertRedirect('/login');
    }

    public function test_recovery_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/recovery')->assertOk()->assertViewIs('recovery');
    }

    public function test_rebuild_requires_authentication(): void
    {
        $this->post('/rebuild')->assertRedirect('/login');
    }

    public function test_rebuild_fails_gracefully_without_package_json(): void
    {
        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gwm-test-'.uniqid();
        // Recreate the directory structure that storage_path() and log writing expect
        mkdir($tmpDir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs', 0755, true);

        $originalBase = $this->app->basePath();
        $this->app->setBasePath($tmpDir);

        try {
            $user = User::factory()->create();
            $response = $this->actingAs($user)->post('/rebuild');

            $response->assertRedirect(route('recovery.index'));
            $this->assertStringContainsString('package.json not found', session('rebuild_status') ?? '');
        } finally {
            $this->app->setBasePath($originalBase);
            // Clean up temp tree
            $log = $tmpDir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'gwm-rebuild.log';
            if (file_exists($log)) {
                unlink($log);
            }
            @rmdir($tmpDir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');
            @rmdir($tmpDir.DIRECTORY_SEPARATOR.'storage');
            @rmdir($tmpDir);
        }
    }
}
