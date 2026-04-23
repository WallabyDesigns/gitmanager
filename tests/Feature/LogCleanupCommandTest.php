<?php

namespace Tests\Feature;

use App\Models\AppUpdate;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_cleanup_clears_only_old_logs_by_default_window(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $oldUpdate = AppUpdate::query()->create([
            'status' => 'success',
            'output_log' => 'old update log',
            'started_at' => now()->subDays(45),
            'finished_at' => now()->subDays(45),
        ]);

        $newUpdate = AppUpdate::query()->create([
            'status' => 'success',
            'output_log' => 'new update log',
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
        ]);

        $oldDeployment = Deployment::query()->create([
            'project_id' => $project->id,
            'triggered_by' => $user->id,
            'action' => 'deploy',
            'status' => 'success',
            'output_log' => 'old deployment log',
            'started_at' => now()->subDays(45),
            'finished_at' => now()->subDays(45),
        ]);

        $newDeployment = Deployment::query()->create([
            'project_id' => $project->id,
            'triggered_by' => $user->id,
            'action' => 'deploy',
            'status' => 'success',
            'output_log' => 'new deployment log',
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
        ]);

        $this->artisan('logs:cleanup --days=30')
            ->expectsOutputToContain('Cleared logs older than 30 day(s).')
            ->assertSuccessful();

        $this->assertNull($oldUpdate->fresh()->output_log);
        $this->assertSame('new update log', $newUpdate->fresh()->output_log);
        $this->assertNull($oldDeployment->fresh()->output_log);
        $this->assertSame('new deployment log', $newDeployment->fresh()->output_log);
    }

    public function test_logs_cleanup_all_clears_every_stored_log(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $update = AppUpdate::query()->create([
            'status' => 'success',
            'output_log' => 'update log',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $deployment = Deployment::query()->create([
            'project_id' => $project->id,
            'triggered_by' => $user->id,
            'action' => 'deploy',
            'status' => 'success',
            'output_log' => 'deployment log',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->artisan('logs:cleanup --all')
            ->expectsOutputToContain('Cleared all stored logs.')
            ->assertSuccessful();

        $this->assertNull($update->fresh()->output_log);
        $this->assertNull($deployment->fresh()->output_log);
    }

    public function test_logs_cleanup_dry_run_does_not_modify_records(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $update = AppUpdate::query()->create([
            'status' => 'success',
            'output_log' => 'update log',
            'started_at' => now()->subDays(45),
            'finished_at' => now()->subDays(45),
        ]);

        $deployment = Deployment::query()->create([
            'project_id' => $project->id,
            'triggered_by' => $user->id,
            'action' => 'deploy',
            'status' => 'success',
            'output_log' => 'deployment log',
            'started_at' => now()->subDays(45),
            'finished_at' => now()->subDays(45),
        ]);

        $this->artisan('logs:cleanup --days=30 --dry-run')
            ->expectsOutputToContain('Would clear logs older than 30 day(s).')
            ->assertSuccessful();

        $this->assertSame('update log', $update->fresh()->output_log);
        $this->assertSame('deployment log', $deployment->fresh()->output_log);
    }
}
