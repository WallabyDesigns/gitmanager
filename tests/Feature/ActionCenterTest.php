<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_action_center_loads_for_authenticated_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('projects.action-center'))
            ->assertOk()
            ->assertSee('Action Center');
    }

    public function test_licensing_page_shows_buy_enterprise_button(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('system.licensing'))
            ->assertOk()
            ->assertSee('Buy Enterprise');
    }
}
