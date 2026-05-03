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
}
