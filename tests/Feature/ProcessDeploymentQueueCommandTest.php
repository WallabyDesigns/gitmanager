<?php

namespace Tests\Feature;

use App\Services\DeploymentQueueService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProcessDeploymentQueueCommandTest extends TestCase
{
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
}
