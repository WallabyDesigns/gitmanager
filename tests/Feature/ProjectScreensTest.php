<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
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

    public function test_project_show_environment_tab_is_available_for_existing_project_path(): void
    {
        $user = User::factory()->create();
        $path = storage_path('framework/testing/project-env-tab');
        File::ensureDirectoryExists($path);

        $project = Project::factory()->create([
            'user_id' => $user->id,
            'local_path' => $path,
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response
            ->assertOk()
            ->assertSee('Environment')
            ->assertDontSee('No .env or .env.example detected');
    }

    public function test_project_env_editor_can_create_missing_env_file(): void
    {
        $user = User::factory()->create();
        $path = storage_path('framework/testing/project-env-editor');
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);

        $project = Project::factory()->create([
            'user_id' => $user->id,
            'local_path' => $path,
            'project_type' => 'custom',
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Projects\EnvEditor::class, ['project' => $project])
            ->set('envContent', "APP_ENV=production\nAPP_DEBUG=false\n")
            ->call('save')
            ->assertDispatched('env-updated');

        $this->assertFileExists($path.DIRECTORY_SEPARATOR.'.env');
        $this->assertSame("APP_ENV=production\nAPP_DEBUG=false\n", file_get_contents($path.DIRECTORY_SEPARATOR.'.env'));
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
