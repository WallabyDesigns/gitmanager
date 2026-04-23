<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_scheduler_route_redirects_to_system_scheduler(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/projects/scheduler')
            ->assertRedirect('/system/scheduler');
    }

    public function test_system_scheduler_page_loads_for_admin(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/system/scheduler')
            ->assertOk()
            ->assertSee('Scheduler Status')
            ->assertSee('Task Frequency')
            ->assertDontSee('License Verification');
    }
}
