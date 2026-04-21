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
}
