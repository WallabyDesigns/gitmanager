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

    public function test_recovery_page_renders_for_admin_user(): void
    {
        $admin = User::factory()->create(['id' => 1]);

        $this->actingAs($admin)->get('/recovery')->assertOk()->assertViewIs('recovery');
    }

    public function test_recovery_page_is_forbidden_for_non_admin_user(): void
    {
        // A user with id > 1 is not an admin (see User::isAdmin()). The
        // recovery page lists and can restore/delete .env backups, so
        // non-admins must not reach it.
        $nonAdmin = User::factory()->create(['id' => 2]);

        $this->actingAs($nonAdmin)->get('/recovery')->assertForbidden();
    }

    public function test_env_backup_actions_are_forbidden_for_non_admin_user(): void
    {
        $nonAdmin = User::factory()->create(['id' => 2]);

        $this->actingAs($nonAdmin)->post('/recovery/env-backup')->assertForbidden();
        $this->actingAs($nonAdmin)->post('/recovery/env-backup/env-backup-test.txt/restore')->assertForbidden();
        $this->actingAs($nonAdmin)->post('/recovery/env-backup/env-backup-test.txt/delete')->assertForbidden();
    }

    public function test_rebuild_requires_authentication(): void
    {
        $this->post('/rebuild')->assertRedirect('/login');
    }

    public function test_rebuild_is_forbidden_for_non_admin_user(): void
    {
        $nonAdmin = User::factory()->create(['id' => 2]);

        $this->actingAs($nonAdmin)->post('/rebuild')->assertForbidden();
    }

    public function test_repair_refreshes_published_assets_without_node_build(): void
    {
        $admin = User::factory()->create(['id' => 1]);

        $response = $this->actingAs($admin)->post('/rebuild');

        $response->assertRedirect(route('recovery.index'));
        $this->assertStringContainsString('Asset repair complete', session('repair_status') ?? '');
    }
}
