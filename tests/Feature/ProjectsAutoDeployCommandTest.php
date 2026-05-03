<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class ProjectsAutoDeployCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_health_check_command_runs_for_monitored_projects_without_a_prior_deployment(): void
    {
        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'auto_deploy' => false,
            'health_url' => 'https://example.test/up',
            'last_deployed_at' => null,
            'last_deployed_hash' => null,
        ]);

        $this->mock(DeploymentService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('checkForUpdates')->never();
            $mock->shouldReceive('checkHealth')
                ->once()
                ->with(Mockery::on(fn (Project $checked) => $checked->is($project)), true, true)
                ->andReturn('ok');
            $mock->shouldReceive('flushHealthNotifications')->once();
        });

        $this->assertSame(0, Artisan::call('projects:health-check'));
    }
}
