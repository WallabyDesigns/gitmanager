<?php

namespace Tests\Unit;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\HealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_forced_health_checks_create_history_entries_even_when_status_does_not_change(): void
    {
        Http::fake([
            'https://example.test/up' => Http::response('OK', 200),
        ]);

        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'static',
            'health_url' => 'https://example.test/up',
            'health_status' => 'ok',
        ]);

        $service = app(HealthCheckService::class);

        $this->assertSame('ok', $service->checkHealth($project, true));
        $this->assertSame('ok', $service->checkHealth($project->fresh(), true));

        $this->assertSame(2, Deployment::query()
            ->where('project_id', $project->id)
            ->where('action', 'health_check')
            ->where('status', 'success')
            ->count());
    }

    public function test_first_http_exception_still_records_health_check_attempt(): void
    {
        Http::fake([
            'https://example.test/up' => fn () => throw new \RuntimeException('Connection timed out'),
        ]);

        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'static',
            'health_url' => 'https://example.test/up',
            'health_status' => 'ok',
            'health_checked_at' => null,
        ]);

        $service = app(HealthCheckService::class);

        $this->assertSame('ok', $service->checkHealth($project, true));

        $project->refresh();
        $this->assertSame('ok', $project->health_status);
        $this->assertNotNull($project->health_checked_at);
        $this->assertStringContainsString('Connection timed out', (string) $project->health_log);

        $this->assertDatabaseHas('deployments', [
            'project_id' => $project->id,
            'action' => 'health_check',
            'status' => 'failed',
        ]);
    }
}
