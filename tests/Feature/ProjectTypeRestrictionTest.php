<?php

namespace Tests\Feature;

use App\Livewire\Projects\Create;
use App\Livewire\Projects\Edit;
use App\Models\Project;
use App\Models\User;
use App\Services\EditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('cargoProjectTypes')]
    public function test_cargo_project_type_is_enterprise_only_and_has_cargo_defaults(string $type): void
    {
        $user = User::factory()->create();
        $this->mockCommunityEdition();

        $community = Livewire::actingAs($user)->test(Create::class);
        $rustType = collect($community->instance()->projectTypes)->firstWhere('value', $type);
        $this->assertTrue((bool) ($rustType['locked'] ?? false));

        $form = $community->instance()->form;
        $form['project_type'] = $type;

        $community
            ->set('form', $form)
            ->call('save')
            ->assertHasErrors(['form.project_type']);

        $this->mockEnterpriseEdition();
        $enterprise = Livewire::actingAs($user)->test(Create::class);

        $rustType = collect($enterprise->instance()->projectTypes)->firstWhere('value', $type);
        $this->assertFalse((bool) ($rustType['locked'] ?? true));

        $enterprise->set('form.project_type', $type);

        $this->assertSame('cargo build --release', $enterprise->instance()->form['build_command']);
        $this->assertSame('cargo test', $enterprise->instance()->form['test_command']);
        $this->assertTrue((bool) $enterprise->instance()->form['run_build_command']);
        $this->assertTrue((bool) $enterprise->instance()->form['run_test_command']);
        $this->assertFalse((bool) $enterprise->instance()->form['run_composer_install']);
        $this->assertFalse((bool) $enterprise->instance()->form['run_npm_install']);
        $this->assertFalse((bool) $enterprise->instance()->form['allow_dependency_updates']);
        $this->assertSame('', $enterprise->instance()->form['health_url']);
    }

    public static function cargoProjectTypes(): array
    {
        return [['rust'], ['larust']];
    }

    public function test_larust_can_be_saved_on_edit_in_enterprise_and_is_rejected_in_community(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'project_type' => 'rust',
            'build_command' => 'cargo build --release --workspace',
            'test_command' => 'cargo test --workspace',
            'run_composer_install' => false,
            'run_npm_install' => false,
            'run_build_command' => true,
            'run_test_command' => true,
            'allow_dependency_updates' => false,
            'ftp_enabled' => false,
            'ssh_enabled' => false,
        ]);
        $this->mockCommunityEdition();
        $community = Livewire::actingAs($user)->test(Edit::class, ['project' => $project]);
        $form = $community->instance()->form;
        $form['project_type'] = 'larust';
        $community->set('form', $form)->call('save')->assertHasErrors(['form.project_type']);
        $this->assertSame('rust', $project->fresh()->project_type);

        $this->mockEnterpriseEdition();
        Livewire::actingAs($user)->test(Edit::class, ['project' => $project])
            ->set('form.project_type', 'larust')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('larust', $project->fresh()->project_type);
        $this->assertSame('cargo build --release --workspace', $project->fresh()->build_command);
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

    private function mockEnterpriseEdition(): void
    {
        $this->mock(EditionService::class, function ($mock): void {
            $mock->shouldReceive('current')->andReturn(EditionService::ENTERPRISE);
        });
    }
}
