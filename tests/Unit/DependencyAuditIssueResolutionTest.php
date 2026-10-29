<?php

namespace Tests\Unit;

use App\Models\AuditIssue;
use App\Models\Project;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependencyAuditIssueResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_composer_update_resolves_open_composer_audit_issue_only(): void
    {
        $project = Project::factory()->create([
            'user_id' => User::factory(),
        ]);

        $composerIssue = AuditIssue::query()->create([
            'project_id' => $project->id,
            'tool' => 'composer',
            'status' => 'open',
            'summary' => 'Composer audit found advisories.',
            'remaining_count' => 2,
        ]);

        $npmIssue = AuditIssue::query()->create([
            'project_id' => $project->id,
            'tool' => 'npm',
            'status' => 'open',
            'summary' => 'Npm audit found vulnerabilities.',
            'remaining_count' => 1,
        ]);

        $output = [];
        $this->invokeResolver($project, 'composer_update', $output);

        $composerIssue->refresh();
        $npmIssue->refresh();

        $this->assertSame('resolved', $composerIssue->status);
        $this->assertSame('Composer update completed successfully.', $composerIssue->fix_summary);
        $this->assertSame(0, $composerIssue->remaining_count);
        $this->assertNotNull($composerIssue->resolved_at);
        $this->assertSame('open', $npmIssue->status);
        $this->assertContains('Resolved 1 open Composer audit issue.', $output);
    }

    public function test_dependency_update_resolves_enabled_dependency_tools(): void
    {
        $project = Project::factory()->create([
            'user_id' => User::factory(),
            'run_composer_install' => true,
            'run_npm_install' => true,
        ]);

        $composerIssue = AuditIssue::query()->create([
            'project_id' => $project->id,
            'tool' => 'composer',
            'status' => 'open',
            'remaining_count' => 1,
        ]);

        $npmIssue = AuditIssue::query()->create([
            'project_id' => $project->id,
            'tool' => 'npm',
            'status' => 'open',
            'remaining_count' => 3,
        ]);

        $output = [];
        $this->invokeResolver($project, 'dependency_update', $output);

        $this->assertSame('resolved', $composerIssue->fresh()->status);
        $this->assertSame('resolved', $npmIssue->fresh()->status);
        $this->assertContains('Resolved 1 open Composer audit issue.', $output);
        $this->assertContains('Resolved 1 open Npm audit issue.', $output);
    }

    public function test_composer_runner_reports_missing_manifest_directory_before_running_process(): void
    {
        $path = storage_path('framework/testing/missing-composer-'.uniqid());
        mkdir($path, 0775, true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('composer.json was not found in: '.$path);

            $output = [];
            $service = app(DeploymentService::class);
            $method = new \ReflectionMethod($service, 'runComposerCommandWithFallback');
            $method->setAccessible(true);
            $method->invokeArgs($service, [$path, &$output, 'Composer update', ['composer', 'update']]);
        } finally {
            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function test_npm_runner_reports_missing_manifest_directory_before_running_process(): void
    {
        $path = storage_path('framework/testing/missing-package-'.uniqid());
        mkdir($path, 0775, true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('package.json was not found in: '.$path);

            $output = [];
            $service = app(DeploymentService::class);
            $method = new \ReflectionMethod($service, 'runNpmCommandWithFallback');
            $method->setAccessible(true);
            $method->invokeArgs($service, [$path, &$output, 'Npm update', ['npm', 'update'], true]);
        } finally {
            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function test_cargo_audit_log_parser_counts_vulnerabilities_from_json_report(): void
    {
        $clean = "Cargo audit (path: /app)\n\$ cargo audit --json\n".json_encode([
            'vulnerabilities' => ['found' => false, 'count' => 0, 'list' => []],
        ]);
        $dirty = json_encode([
            'vulnerabilities' => ['found' => true, 'count' => 2, 'list' => [['advisory' => ['id' => 'RUSTSEC-2024-0001']], ['advisory' => ['id' => 'RUSTSEC-2024-0002']]]],
        ]);

        $this->assertSame(0, $this->parseCargoLog($clean));
        $this->assertSame(2, $this->parseCargoLog($dirty));
    }

    public function test_cargo_audit_log_parser_returns_null_for_non_report_output(): void
    {
        $this->assertNull($this->parseCargoLog(''));
        $this->assertNull($this->parseCargoLog("error: no such command: `audit`"));
    }

    public function test_cargo_audit_failure_is_not_recorded_as_a_vulnerability_result(): void
    {
        $project = Project::factory()->create(['user_id' => User::factory()]);
        $service = $this->partialMock(DeploymentService::class, function ($mock) use ($project) {
            $deployment = new \App\Models\Deployment(['project_id' => $project->id, 'action' => 'cargo_audit', 'status' => 'failed']);
            $deployment->id = 1;
            $mock->shouldReceive('cargoAudit')->once()->andReturn($deployment);
        });

        $method = new \ReflectionMethod(DeploymentService::class, 'runCargoAuditFlow');
        $method->setAccessible(true);
        $result = $method->invoke($service, $project, null);

        $this->assertSame('cargo', $result['tool']);
        $this->assertSame('failed', $result['status']);
        $this->assertNull($result['remaining']);
    }

    private function parseCargoLog(string $log): ?int
    {
        $service = app(DeploymentService::class);
        $method = new \ReflectionMethod($service, 'parseCargoAuditLog');
        $method->setAccessible(true);

        return $method->invoke($service, $log);
    }

    /**
     * @param  array<int, string>  $output
     */
    private function invokeResolver(Project $project, string $action, array &$output): void
    {
        $service = app(DeploymentService::class);
        $method = new \ReflectionMethod($service, 'resolveDependencyAuditIssuesForAction');
        $method->setAccessible(true);
        $method->invokeArgs($service, [$project, $action, &$output]);
    }
}
