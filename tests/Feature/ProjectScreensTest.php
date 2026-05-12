<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_project_screen_loads(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('projects.create'));

        $response
            ->assertOk()
            ->assertSee('Create Project');
    }

    public function test_project_index_screen_loads_grouped_directories(): void
    {
        $user = User::factory()->create();
        Project::factory()->create([
            'user_id' => $user->id,
            'directory_path' => 'Clients/Acme',
            'name' => 'Acme Site',
        ]);

        $response = $this->actingAs($user)->get(route('projects.index'));

        $response
            ->assertOk()
            ->assertSee('Clients')
            ->assertSee('Acme')
            ->assertDontSee('refreshHealth');
    }

    public function test_edit_project_screen_loads(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('projects.edit', $project));

        $response
            ->assertOk()
            ->assertSee('Edit '.$project->name);
    }

    public function test_project_show_screen_loads(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response
            ->assertOk()
            ->assertSee('Latest Debug Logs')
            ->assertDontSee('refreshHealthStatus');
    }

    public function test_sub_user_can_see_and_manage_projects_created_by_other_users(): void
    {
        $owner = User::factory()->create();
        $subUser = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Shared Workspace Project',
        ]);

        $this->actingAs($subUser)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Shared Workspace Project');

        $this->actingAs($subUser)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Latest Debug Logs');

        $this->actingAs($subUser)
            ->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('Edit '.$project->name);
    }

    public function test_queue_screen_loads(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('projects.queue'));

        $response
            ->assertOk()
            ->assertSee('Queue Runner');
    }
}
