<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\Index as DashboardIndex;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use App\Services\DockerService;
use App\Services\EditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_health_button_checks_workspace_projects(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $first = Project::factory()->create(['user_id' => $user->id]);
        $second = Project::factory()->create(['user_id' => $user->id]);
        $third = Project::factory()->create(['user_id' => $otherUser->id]);

        $this->mockDashboardInfrastructure();

        $this->mock(DeploymentService::class, function ($mock) use ($first, $second, $third): void {
            $projectIds = [$first->id, $second->id, $third->id];

            $mock->shouldReceive('checkHealth')
                ->times(3)
                ->withArgs(fn (Project $project): bool => in_array($project->id, $projectIds, true))
                ->andReturn('ok');
        });

        Livewire::actingAs($user)
            ->test(DashboardIndex::class)
            ->call('checkAllHealth');
    }

    public function test_dashboard_update_button_checks_workspace_projects(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $first = Project::factory()->create([
            'user_id' => $user->id,
            'updates_checked_at' => now()->subMinutes(10),
        ]);
        $second = Project::factory()->create([
            'user_id' => $user->id,
            'updates_checked_at' => null,
        ]);
        $third = Project::factory()->create([
            'user_id' => $otherUser->id,
            'updates_checked_at' => now()->subMinutes(10),
        ]);

        $this->mockDashboardInfrastructure();

        $this->mock(DeploymentService::class, function ($mock) use ($first, $second, $third): void {
            $projectIds = [$first->id, $second->id, $third->id];

            $mock->shouldReceive('checkForUpdates')
                ->times(3)
                ->withArgs(fn (Project $project): bool => in_array($project->id, $projectIds, true))
                ->andReturnFalse();
            $mock->shouldReceive('checkHealth')->never();
            $mock->shouldReceive('flushHealthNotifications')->once();
        });

        Livewire::actingAs($user)
            ->test(DashboardIndex::class)
            ->call('checkAllUpdates');
    }

    public function test_dashboard_audit_button_queues_project_audits_for_enterprise(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);

        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'permissions_locked' => false,
            'ftp_enabled' => false,
            'ssh_enabled' => false,
        ]);

        $this->mockDashboardInfrastructure();
        $this->mockEnterpriseEdition();

        Livewire::actingAs($user)
            ->test(DashboardIndex::class)
            ->call('auditAllProjects');

        $this->assertDatabaseHas('deployment_queue_items', [
            'project_id' => $project->id,
            'queued_by' => $user->id,
            'action' => 'audit_project',
            'status' => 'queued',
        ]);
    }

    private function mockDashboardInfrastructure(): void
    {
        $this->mock(DockerService::class, function ($mock): void {
            $mock->shouldReceive('isAvailable')->andReturnFalse();
        });
    }

    private function mockEnterpriseEdition(): void
    {
        $this->mock(EditionService::class, function ($mock): void {
            $mock->shouldReceive('current')->andReturn(EditionService::ENTERPRISE);
            $mock->shouldReceive('label')->andReturn('Enterprise Edition');
        });
    }
}
