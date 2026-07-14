<?php

namespace Tests\Unit;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DeploymentQueueService;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticAuditPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_composer_audit_fixes_request_with_all_dependencies(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);
        $project = Project::factory()->create(['user_id' => User::factory()]);

        $this->mock(DeploymentQueueService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('enqueue')->once()->with(
                $project,
                'composer_update',
                ['reason' => 'audit_fix', 'with_all_dependencies' => true],
            );
        });

        $service = app(AuditService::class);
        $method = new \ReflectionMethod($service, 'queueFixForTool');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, $project, 'composer'));
    }

    public function test_automatic_audit_push_commits_only_composer_dependency_files(): void
    {
        $project = Project::factory()->create(['user_id' => User::factory()]);
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'action' => 'composer_update',
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $deploymentService = $this->mock(DeploymentService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('getWorkingTreeChanges')->once()->with($project)->andReturn([
                'dirty' => true,
                'files' => ['composer.lock', 'README.md'],
            ]);
            $mock->shouldReceive('commitAndPush')->once()->with(
                $project,
                'chore: apply composer security updates',
                ['composer.lock'],
            )->andReturn(['status' => 'pushed', 'output' => ['Pushed origin/main.']]);
        });

        $queue = app(DeploymentQueueService::class);
        $method = new \ReflectionMethod($queue, 'commitAndPushAuditFix');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($queue, $deploymentService, $project, $deployment, 'composer_update'));
        $this->assertSame('success', $deployment->fresh()->status);
        $this->assertStringContainsString('committed and pushed dependency changes', $deployment->fresh()->output_log);
        $this->assertStringNotContainsString('README.md', $deployment->fresh()->output_log);
    }
}
