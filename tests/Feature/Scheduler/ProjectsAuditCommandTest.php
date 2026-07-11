<?php

namespace Tests\Feature\Scheduler;

use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentQueueService;
use App\Services\EditionService;
use App\Services\LicenseService;
use App\Services\SchedulerService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProjectsAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_enterprise_scheduler_queues_dependency_audits_with_automatic_fixes(): void
    {
        $project = Project::factory()->create(['user_id' => User::factory()]);

        $this->mock(SettingsService::class, function ($mock): void {
            $mock->shouldReceive('get')->once()->with('system.audit_enabled', false)->andReturn(true);
        });
        $this->mock(EditionService::class, function ($mock): void {
            $mock->shouldReceive('current')->once()->andReturn(EditionService::ENTERPRISE);
        });
        $this->mock(LicenseService::class, function ($mock): void {
            $mock->shouldReceive('hasValidEnterpriseLicense')->once()->andReturn(true);
        });
        $this->mock(SchedulerService::class, function ($mock): void {
            $mock->shouldReceive('recordHeartbeat')->once()->with('schedule');
        });
        $this->mock(DeploymentQueueService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('enqueue')->once()->with(
                \Mockery::on(fn (Project $candidate) => $candidate->is($project)),
                'audit_project',
                [
                    'auto_fix' => true,
                    'send_email' => true,
                    'source' => 'scheduled_vulnerability_audit',
                ],
            )->andReturn(new DeploymentQueueItem);
        });

        $exitCode = Artisan::call('projects:audit');

        $this->assertSame(0, $exitCode);
    }

    public function test_scheduler_does_not_queue_audits_when_enterprise_auditing_is_disabled(): void
    {
        Project::factory()->create(['user_id' => User::factory()]);

        $this->mock(SettingsService::class, function ($mock): void {
            $mock->shouldReceive('get')->once()->with('system.audit_enabled', false)->andReturn(false);
        });
        $this->mock(EditionService::class, function ($mock): void {
            $mock->shouldReceive('current')->never();
        });
        $this->mock(LicenseService::class, function ($mock): void {
            $mock->shouldReceive('hasValidEnterpriseLicense')->never();
        });
        $this->mock(SchedulerService::class, function ($mock): void {
            $mock->shouldReceive('recordHeartbeat')->once()->with('schedule');
        });
        $this->mock(DeploymentQueueService::class, function ($mock): void {
            $mock->shouldReceive('enqueue')->never();
        });

        $exitCode = Artisan::call('projects:audit');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('disabled or unavailable', Artisan::output());
    }
}
