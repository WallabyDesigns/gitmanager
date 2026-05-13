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

    public function test_repair_refreshes_published_assets_without_node_build(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/rebuild');

        $response->assertRedirect(route('recovery.index'));
        $this->assertStringContainsString('Asset repair complete', session('repair_status') ?? '');
    }
}
