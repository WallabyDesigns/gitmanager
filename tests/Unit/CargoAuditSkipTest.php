<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\AuditService;
use App\Services\Plugins\CargoAuditPlugin;
use Tests\TestCase;

class CargoAuditSkipTest extends TestCase
{
    public function test_skipped_audit_result_records_no_issue_and_no_notification(): void
    {
        $service = app(AuditService::class);
        $method = new \ReflectionMethod($service, 'recordAuditIssue');

        $project = new Project(['name' => 'rust-app']);
        $project->id = 999999;

        $record = $method->invoke($service, $project, 'cargo', ['status' => 'skipped', 'remaining' => null]);

        $this->assertFalse($record['opened']);
        $this->assertFalse($record['resolved']);
        $this->assertNull($record['notification']);
    }

    public function test_plugin_reports_not_installed_without_binary(): void
    {
        $plugin = new class extends CargoAuditPlugin
        {
            public function binaryPath(): ?string
            {
                return null;
            }
        };

        $this->assertFalse($plugin->isInstalled());
        $this->assertNull($plugin->installedVersion());
    }
}
