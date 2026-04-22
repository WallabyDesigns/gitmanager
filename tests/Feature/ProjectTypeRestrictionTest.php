<?php

namespace Tests\Feature;

use App\Livewire\Projects\Create;
use App\Livewire\Projects\Edit;
use App\Models\Project;
use App\Models\User;
use App\Services\EditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectTypeRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_project_type_is_locked_and_rejected_on_create_for_community_edition(): void
    {
        $user = User::factory()->create();
        $this->mockCommunityEdition();

        $component = Livewire::actingAs($user)->test(Create::class);

        $customType = collect($component->instance()->projectTypes)->firstWhere('value', 'custom');
        $this->assertTrue((bool) ($customType['locked'] ?? false));

        $form = $component->instance()->form;
        $form['project_type'] = 'custom';

        $component
            ->set('form', $form)
            ->call('save')
            ->assertHasErrors(['form.project_type']);

        $this->assertDatabaseMissing('projects', [
            'user_id' => $user->id,
            'project_type' => 'custom',
        ]);
    }

    public function test_custom_project_type_is_locked_and_rejected_on_edit_for_community_edition(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'project_type' => 'laravel',
        ]);
        $this->mockCommunityEdition();

        $component = Livewire::actingAs($user)->test(Edit::class, ['project' => $project]);

        $customType = collect($component->instance()->projectTypes)->firstWhere('value', 'custom');
        $this->assertTrue((bool) ($customType['locked'] ?? false));

        $form = $component->instance()->form;
        $form['project_type'] = 'custom';

        $component
            ->set('form', $form)
            ->call('save')
            ->assertHasErrors(['form.project_type']);

        $this->assertSame('laravel', (string) $project->fresh()->project_type);
    }

    private function mockCommunityEdition(): void
    {
        $this->mock(EditionService::class, function ($mock): void {
            $mock->shouldReceive('current')->andReturn(EditionService::COMMUNITY);
        });
    }
}
