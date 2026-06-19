<?php

namespace Tests\Feature;

use App\Livewire\Projects\DependencyActions;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DependencyActionsConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_actions_are_based_on_project_type_without_local_files(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'project_type' => 'laravel',
            'local_path' => storage_path('framework/testing/missing-laravel-project'),
        ]);

        Livewire::actingAs($user)
            ->test(DependencyActions::class, ['project' => $project])
            ->assertSee('Composer')
            ->assertSee('Npm')
            ->assertSee('Laravel')
            ->assertSee('Migrate');
    }

    public function test_user_can_override_visible_dependency_actions(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'project_type' => 'laravel',
        ]);

        Livewire::actingAs($user)
            ->test(DependencyActions::class, ['project' => $project])
            ->set('enabledActions.composer_install', true)
            ->set('enabledActions.composer_update', false)
            ->set('enabledActions.composer_audit', false)
            ->set('enabledActions.app_clear_cache', false)
            ->set('enabledActions.laravel_migrate', false)
            ->set('enabledActions.npm_install', false)
            ->set('enabledActions.npm_update', false)
            ->set('enabledActions.npm_audit_fix', false)
            ->set('enabledActions.npm_audit_fix_force', false)
            ->call('saveActionSettings');

        $this->assertSame(['composer_install'], $project->fresh()->dependency_actions);
    }

    public function test_user_can_add_and_remove_custom_dependency_actions(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'project_type' => 'custom',
        ]);

        $component = Livewire::actingAs($user)
            ->test(DependencyActions::class, ['project' => $project])
            ->set('newCustomActionName', 'Restart Queue')
            ->set('newCustomActionCommand', 'php artisan queue:restart')
            ->call('addCustomAction')
            ->assertSee('Restart Queue');

        $saved = $project->fresh()->custom_dependency_actions;
        $this->assertCount(1, $saved);
        $this->assertSame('php artisan queue:restart', $saved[0]['command']);

        $component->call('removeCustomAction', $saved[0]['id']);

        $this->assertSame([], $project->fresh()->custom_dependency_actions);
    }
}
