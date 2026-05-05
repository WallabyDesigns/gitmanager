<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\User;
use App\Services\HealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_forced_health_checks_update_project_health_history_without_deployment_logs(): void
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

        $project->refresh();
        $history = $project->healthHistory();

        $this->assertSame(2, $project->healthHistoryJsonEntryCount());
        $this->assertSame('success', $history[0]['deployment_status']);
        $this->assertSame(200, $history[0]['http_status']);

        $this->assertDatabaseMissing('deployments', [
            'project_id' => $project->id,
            'action' => 'health_check',
        ]);
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
        $this->assertSame(1, $project->healthHistoryJsonEntryCount());
        $this->assertSame('failed', $project->healthHistory()[0]['deployment_status']);
        $this->assertStringContainsString('Connection timed out', (string) $project->healthHistory()[0]['issue']);

        $this->assertDatabaseMissing('deployments', [
            'project_id' => $project->id,
            'action' => 'health_check',
        ]);
    }

    public function test_transport_exceptions_are_retried_before_recording_the_result(): void
    {
        $attempts = 0;
        Http::fake([
            'https://example.test/up' => function () use (&$attempts) {
                $attempts++;

                if ($attempts < 3) {
                    throw new \RuntimeException('cURL error 6: getaddrinfo() thread failed to start');
                }

                return Http::response('OK', 200);
            },
        ]);

        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'static',
            'health_url' => 'https://example.test/up',
            'health_status' => 'ok',
        ]);

        $service = app(HealthCheckService::class);

        $this->assertSame('ok', $service->checkHealth($project, true));

        $project->refresh();

        $this->assertSame('ok', $project->health_status);
        $this->assertNull($project->health_issue_message);
        $this->assertSame('success', $project->healthHistory()[0]['deployment_status']);
        $this->assertSame(200, $project->healthHistory()[0]['http_status']);
        $this->assertSame(3, $attempts);
    }

    public function test_transport_exceptions_must_repeat_before_marking_project_down(): void
    {
        Http::fake([
            'https://example.test/up' => fn () => throw new \RuntimeException('cURL error 6: getaddrinfo() thread failed to start'),
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
        $this->assertSame('na', $service->checkHealth($project->fresh(), true));

        $project->refresh();

        $this->assertSame('na', $project->health_status);
        $this->assertStringContainsString('HTTP transport failed: cURL error 6', (string) $project->health_issue_message);
        $this->assertSame(3, $project->healthHistoryJsonEntryCount());
    }

    public function test_health_history_keeps_only_last_sixty_entries(): void
    {
        Http::fake([
            'https://example.test/up' => Http::response('OK', 200),
        ]);

        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'static',
            'health_url' => 'https://example.test/up',
        ]);

        $service = app(HealthCheckService::class);

        for ($i = 0; $i < 65; $i++) {
            $this->assertSame('ok', $service->checkHealth($project->fresh(), true));
        }

        $project->refresh();

        $this->assertSame(Project::HEALTH_HISTORY_LIMIT, $project->healthHistoryJsonEntryCount());
    }
}
