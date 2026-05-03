<?php

namespace Tests\Unit;

use App\Models\AppUpdate;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Support\ConsoleOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsoleOutputTest extends TestCase
{
    use RefreshDatabase;

    public function test_php_warning_lines_are_removed_from_console_output(): void
    {
        $output = implode("\n", [
            'Starting update',
            'PHP Warning: Module "imagick" is already loaded in Unknown on line 0',
            'Actual useful output',
            '  PHP Warning: Something noisy',
            'Finished',
        ]);

        $this->assertSame(
            "Starting update\nActual useful output\nFinished",
            ConsoleOutput::withoutPhpWarnings($output)
        );
    }

    public function test_stored_output_logs_are_sanitized_when_read(): void
    {
        $project = Project::factory()->create(['user_id' => User::factory()]);

        $deployment = Deployment::query()->create([
            'project_id' => $project->id,
            'triggered_by' => $project->user_id,
            'action' => 'deploy',
            'status' => 'success',
            'output_log' => "PHP Warning: noisy\nDeploy complete",
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $update = AppUpdate::query()->create([
            'status' => 'success',
            'output_log' => "PHP Warning: noisy\nUpdate complete",
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->assertSame('Deploy complete', $deployment->fresh()->output_log);
        $this->assertSame('Update complete', $update->fresh()->output_log);
    }
}
