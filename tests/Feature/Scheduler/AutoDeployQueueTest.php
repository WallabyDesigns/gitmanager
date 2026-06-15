<?php

namespace Tests\Feature\Scheduler;

use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use App\Services\SchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tests for the projects:auto-deploy scheduler command.
 *
 * NOTE: The command described in the task spec (RebuildStaticSites /
 * rebuild_static_sites) does not exist in the codebase.  The closest
 * real equivalent is projects:auto-deploy (App\Console\Commands\ProjectsAutoDeploy),
 * which is what these tests exercise.
 *
 * The command calls DeploymentQueueService::enqueue() (not
 * enqueueForImmediateProcessing()) — tests mock accordingly.
 */
class AutoDeployQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable queue mode so the command enqueues rather than deploying inline.
        config()->set('gitmanager.deploy_queue.enabled', true);
    }

    // -----------------------------------------------------------------------
    // Skips projects that do NOT have auto_deploy enabled
    // -----------------------------------------------------------------------

    public function test_command_does_not_enqueue_projects_with_auto_deploy_disabled(): void
    {
        $owner = User::factory()->create();
        Project::factory()->create([
            'user_id' => $owner->id,
            'auto_deploy' => false,
        ]);

        $this->mock(DeploymentQueueService::class, function ($mock): void {
            $mock->shouldReceive('enqueue')->never();
            $mock->shouldReceive('enqueueForImmediateProcessing')->never();
        });

        // DeploymentService must still be mocked to avoid real git calls.
        $this->mock(DeploymentService::class, function ($mock): void {
            $mock->shouldReceive('checkForUpdates')->never();
            $mock->shouldReceive('flushHealthNotifications')->once();
        });

        $this->mock(SchedulerService::class, function ($mock): void {
            $mock->shouldReceive('recordHeartbeat')->once()->with('schedule');
        });

        $exitCode = Artisan::call('projects:auto-deploy');

        $this->assertSame(0, $exitCode);
    }

    // -----------------------------------------------------------------------
    // Queues a project that has updates available
    // -----------------------------------------------------------------------

    public function test_command_enqueues_deploy_for_project_with_auto_deploy_enabled_when_updates_available(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'auto_deploy' => true,
        ]);

        $this->mock(DeploymentService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('checkForUpdates')
                ->once()
                ->with(\Mockery::on(fn (Project $p) => $p->is($project)))
                ->andReturn(true);
            $mock->shouldReceive('flushHealthNotifications')->once();
        });

        $this->mock(DeploymentQueueService::class, function ($mock) use ($project): void {
            $queueItem = new DeploymentQueueItem([
                'project_id' => $project->id,
                'action' => 'deploy',
                'status' => 'queued',
                'position' => 1,
            ]);

            $mock->shouldReceive('enqueue')
                ->once()
                ->with(
                    \Mockery::on(fn (Project $p) => $p->is($project)),
                    'deploy',
                    \Mockery::on(fn (array $payload) => ($payload['reason'] ?? '') === 'auto_update')
                )
                ->andReturn($queueItem);
        });

        $this->mock(SchedulerService::class, function ($mock): void {
            $mock->shouldReceive('recordHeartbeat')->once()->with('schedule');
        });

        $exitCode = Artisan::call('projects:auto-deploy');

        $this->assertSame(0, $exitCode);
    }

    // -----------------------------------------------------------------------
    // Does NOT enqueue when no updates are available
    // -----------------------------------------------------------------------

    public function test_command_does_not_enqueue_when_no_updates_are_available(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'auto_deploy' => true,
        ]);

        $this->mock(DeploymentService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('checkForUpdates')
                ->once()
                ->with(\Mockery::on(fn (Project $p) => $p->is($project)))
                ->andReturn(false);
            $mock->shouldReceive('flushHealthNotifications')->once();
        });

        $this->mock(DeploymentQueueService::class, function ($mock): void {
            $mock->shouldReceive('enqueue')->never();
        });

        $this->mock(SchedulerService::class, function ($mock): void {
            $mock->shouldReceive('recordHeartbeat')->once()->with('schedule');
        });

        $exitCode = Artisan::call('projects:auto-deploy');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No updates', Artisan::output());
    }

    // -----------------------------------------------------------------------
    // Duplicate / already-queued detection via DeploymentQueueService::enqueue()
    // -----------------------------------------------------------------------

    public function test_command_does_not_create_second_queue_item_when_deploy_already_queued(): void
    {
        // This test uses the real DeploymentQueueService so the duplicate-
        // detection logic inside enqueue() is exercised end-to-end.
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'auto_deploy' => true,
        ]);

        // Seed an existing queued deploy item for the project.
        DeploymentQueueItem::create([
            'project_id' => $project->id,
            'action' => 'deploy',
            'status' => 'queued',
            'position' => 1,
        ]);

        $this->mock(DeploymentService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('checkForUpdates')
                ->once()
                ->with(\Mockery::on(fn (Project $p) => $p->is($project)))
                ->andReturn(true);
            $mock->shouldReceive('flushHealthNotifications')->once();
        });

        $this->mock(SchedulerService::class, function ($mock): void {
            $mock->shouldReceive('recordHeartbeat')->once()->with('schedule');
        });

        Artisan::call('projects:auto-deploy');

        // Only the original item should exist — no duplicates.
        $this->assertSame(
            1,
            DeploymentQueueItem::query()
                ->where('project_id', $project->id)
                ->where('action', 'deploy')
                ->whereIn('status', ['queued', 'running'])
                ->count()
        );
    }
}
