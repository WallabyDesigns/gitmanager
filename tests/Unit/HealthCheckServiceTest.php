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
        config([
            'gitmanager.health.stream_fallback_enabled' => false,
            'gitmanager.health.cli_fallback_enabled' => false,
        ]);

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
            'https://example.test/up' => fn () => throw new \RuntimeException('Connection timed out'),
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
        $this->assertStringContainsString('HTTP transport failed: Connection timed out', (string) $project->health_issue_message);
        $this->assertSame(3, $project->healthHistoryJsonEntryCount());
    }

    public function test_getaddrinfo_thread_failures_are_recorded_as_inconclusive_without_marking_project_down(): void
    {
        config([
            'gitmanager.health.stream_fallback_enabled' => false,
            'gitmanager.health.cli_fallback_enabled' => false,
        ]);

        Http::fake([
            'https://example.test/up' => fn () => throw new \RuntimeException('cURL error 6: getaddrinfo() thread failed to start'),
        ]);

        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'static',
            'health_url' => 'https://example.test/up',
            'health_status' => 'ok',
            'health_issue_message' => null,
        ]);

        $service = app(HealthCheckService::class);

        $this->assertSame('ok', $service->checkHealth($project, true));
        $this->assertSame('ok', $service->checkHealth($project->fresh(), true));
        $this->assertSame('ok', $service->checkHealth($project->fresh(), true));

        $project->refresh();
        $history = $project->healthHistory();

        $this->assertSame('ok', $project->health_status);
        $this->assertNull($project->health_issue_message);
        $this->assertSame(3, $project->healthHistoryJsonEntryCount());
        $this->assertSame('inconclusive', $history[0]['status']);
        $this->assertSame('inconclusive', $history[0]['deployment_status']);
        $this->assertSame('Health check inconclusive: local HTTP transport could not complete.', $history[0]['summary']);
        $this->assertStringContainsString('getaddrinfo() thread failed to start', (string) $history[0]['issue']);
    }

    public function test_getaddrinfo_thread_failure_uses_stream_fallback_when_available(): void
    {
        Http::fake([
            'https://example.test/up' => fn () => throw new \RuntimeException('cURL error 6: getaddrinfo() thread failed to start'),
        ]);

        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'static',
            'health_url' => 'https://example.test/up',
            'health_status' => 'ok',
            'health_issue_message' => 'HTTP transport failed: cURL error 6: getaddrinfo() thread failed to start',
        ]);

        $service = new class(
            app(\App\Services\LaravelDeploymentCheckService::class),
            app(\App\Services\PermissionService::class),
            app(\App\Services\SettingsService::class),
        ) extends HealthCheckService {
            protected function fallbackHttpStatus(string $url): ?int
            {
                return $url === 'https://example.test/up' ? 200 : null;
            }
        };

        $this->assertSame('ok', $service->checkHealth($project, true));

        $project->refresh();
        $history = $project->healthHistory();

        $this->assertSame('ok', $project->health_status);
        $this->assertNull($project->health_issue_message);
        $this->assertSame('success', $history[0]['deployment_status']);
        $this->assertSame(200, $history[0]['http_status']);
    }

    public function test_getaddrinfo_thread_failure_uses_bundled_cli_probe_when_stream_fallback_is_unavailable(): void
    {
        $probe = storage_path('framework/testing-gwm-http-probe.php');
        file_put_contents($probe, <<<'PHP'
<?php
echo "HTTP/1.1 204\n";
PHP);

        config([
            'gitmanager.health.stream_fallback_enabled' => false,
            'gitmanager.health.cli_fallback_enabled' => true,
            'gitmanager.health.bundled_probe_enabled' => true,
            'gitmanager.health.bundled_probe_script' => $probe,
            'gitmanager.health.httpie_binary' => '',
            'gitmanager.health.curl_binary' => '',
        ]);

        Http::fake([
            'https://example.test/up' => fn () => throw new \RuntimeException('cURL error 6: getaddrinfo() thread failed to start'),
        ]);

        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'static',
            'health_url' => 'https://example.test/up',
            'health_status' => 'ok',
            'health_issue_message' => 'HTTP transport failed: cURL error 6: getaddrinfo() thread failed to start',
        ]);

        try {
            $service = app(HealthCheckService::class);

            $this->assertSame('ok', $service->checkHealth($project, true));

            $project->refresh();
            $history = $project->healthHistory();

            $this->assertSame('ok', $project->health_status);
            $this->assertNull($project->health_issue_message);
            $this->assertSame('success', $history[0]['deployment_status']);
            $this->assertSame(204, $history[0]['http_status']);
            $this->assertSame('HTTP 204', $history[0]['summary']);
        } finally {
            @unlink($probe);
        }
    }

    public function test_repeated_primary_transport_failures_use_fallback_first_for_cooldown_window(): void
    {
        config([
            'gitmanager.health.stream_fallback_enabled' => true,
            'gitmanager.health.cli_fallback_enabled' => false,
            'gitmanager.health.primary_failure_threshold' => 3,
            'gitmanager.health.primary_fallback_seconds' => 3600,
        ]);

        $primaryAttempts = 0;
        Http::fake([
            'https://example.test/up' => function () use (&$primaryAttempts) {
                $primaryAttempts++;

                throw new \RuntimeException('cURL error 6: getaddrinfo() thread failed to start');
            },
        ]);

        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'project_type' => 'static',
            'health_url' => 'https://example.test/up',
            'health_status' => 'ok',
        ]);

        $service = new class(
            app(\App\Services\LaravelDeploymentCheckService::class),
            app(\App\Services\PermissionService::class),
            app(\App\Services\SettingsService::class),
        ) extends HealthCheckService {
            public int $fallbackAttempts = 0;

            protected function fallbackHttpStatus(string $url): ?int
            {
                $this->fallbackAttempts++;

                return $url === 'https://example.test/up' ? 200 : null;
            }
        };

        $this->assertSame('ok', $service->checkHealth($project, true));
        $this->assertSame('ok', $service->checkHealth($project->fresh(), true));
        $this->assertSame('ok', $service->checkHealth($project->fresh(), true));
        $this->assertSame('ok', $service->checkHealth($project->fresh(), true));

        $this->assertSame(3, $primaryAttempts);
        $this->assertSame(4, $service->fallbackAttempts);

        $project->refresh();
        $this->assertSame('ok', $project->health_status);
        $this->assertSame(4, $project->healthHistoryJsonEntryCount());
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
