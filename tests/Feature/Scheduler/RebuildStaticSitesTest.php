<?php

namespace Tests\Feature\Scheduler;

use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentQueueService;
use App\Services\SchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RebuildStaticSitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('gitmanager.deploy_queue.enabled', true);
        $this->mock(SchedulerService::class, fn ($m) => $m->shouldReceive('recordHeartbeat')->once()->with('schedule'));
    }

    public function test_command_skips_projects_with_rebuild_disabled(): void
    {
        $owner = User::factory()->create();
        Project::factory()->create(['user_id' => $owner->id, 'rebuild_enabled' => false]);

        $this->mock(DeploymentQueueService::class, fn ($m) => $m->shouldReceive('enqueue')->never());

        $exitCode = Artisan::call('projects:rebuild-static');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('0', Artisan::output());
    }

    public function test_command_queues_project_with_no_previous_rebuild(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'rebuild_enabled' => true,
            'rebuild_interval_hours' => 24,
            'last_rebuild_at' => null,
        ]);

        $this->mock(DeploymentQueueService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('enqueue')
                ->once()
                ->with(
                    \Mockery::on(fn (Project $p) => $p->is($project)),
                    'rebuild_static',
                    \Mockery::on(fn (array $payload) => ($payload['source'] ?? '') === 'scheduled_rebuild')
                );
        });

        $exitCode = Artisan::call('projects:rebuild-static');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1', Artisan::output());
    }

    public function test_command_queues_project_whose_interval_has_elapsed(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'rebuild_enabled' => true,
            'rebuild_interval_hours' => 1,
            'last_rebuild_at' => Carbon::now()->subHours(2),
        ]);

        $this->mock(DeploymentQueueService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('enqueue')->once()
                ->with(\Mockery::on(fn (Project $p) => $p->is($project)), 'rebuild_static', \Mockery::any());
        });

        Artisan::call('projects:rebuild-static');
    }

    public function test_command_skips_project_whose_interval_has_not_elapsed(): void
    {
        $owner = User::factory()->create();
        Project::factory()->create([
            'user_id' => $owner->id,
            'rebuild_enabled' => true,
            'rebuild_interval_hours' => 24,
            'last_rebuild_at' => Carbon::now()->subMinutes(30),
        ]);

        $this->mock(DeploymentQueueService::class, fn ($m) => $m->shouldReceive('enqueue')->never());

        $exitCode = Artisan::call('projects:rebuild-static');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('0', Artisan::output());
    }

    public function test_command_skips_project_with_rebuild_already_queued(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'rebuild_enabled' => true,
            'last_rebuild_at' => null,
        ]);

        DeploymentQueueItem::create([
            'project_id' => $project->id,
            'action' => 'rebuild_static',
            'status' => 'queued',
            'position' => 1,
        ]);

        $this->mock(DeploymentQueueService::class, fn ($m) => $m->shouldReceive('enqueue')->never());

        $exitCode = Artisan::call('projects:rebuild-static');

        $this->assertSame(0, $exitCode);
    }
}
