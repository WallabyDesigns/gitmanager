<?php

namespace Tests\Feature;

use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProcessDeploymentQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_command_uses_configured_batch_size_when_limit_is_not_provided(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);
        config()->set('gitmanager.deploy_queue.batch_size', 0);

        $this->mock(DeploymentQueueService::class, function ($mock): void {
            $mock->shouldReceive('processNext')
                ->once()
                ->with(0)
                ->andReturn(5);
        });

        Artisan::call('deployments:process-queue');

        $this->assertStringContainsString('Processed 5 queued item(s).', Artisan::output());
    }

    public function test_queue_command_respects_explicit_limit_override(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);
        config()->set('gitmanager.deploy_queue.batch_size', 0);

        $this->mock(DeploymentQueueService::class, function ($mock): void {
            $mock->shouldReceive('processNext')
                ->once()
                ->with(2)
                ->andReturn(2);
        });

        Artisan::call('deployments:process-queue', ['--limit' => 2]);

        $this->assertStringContainsString('Processed 2 queued item(s).', Artisan::output());
    }

    public function test_running_queue_item_is_not_released_before_process_timeout_buffer(): void
    {
        config()->set('gitmanager.deploy_queue.stale_seconds', 900);
        config()->set('gitmanager.deployments.process_timeout', 1800);

        $project = Project::factory()->create([
            'user_id' => User::factory(),
        ]);
        $item = DeploymentQueueItem::query()->create([
            'project_id' => $project->id,
            'action' => 'deploy',
            'status' => 'running',
            'position' => 1,
            'started_at' => now()->subMinutes(20),
        ]);

        $released = app(DeploymentQueueService::class)->releaseStaleRunning();

        $this->assertSame(0, $released);
        $this->assertSame('running', $item->fresh()->status);
    }
}
