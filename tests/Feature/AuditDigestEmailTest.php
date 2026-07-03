<?php

namespace Tests\Feature;

use App\Models\AuditIssue;
use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuditDigestEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'noreply@example.com',
            'mail.mailers.smtp.host' => 'localhost',
            'mail.mailers.smtp.port' => '25',
        ]);

        app(SettingsService::class)->set('system.audit_email_enabled', true);
    }

    private function makeOpenIssue(Project $project, string $tool, int $remaining): AuditIssue
    {
        return AuditIssue::create([
            'project_id' => $project->id,
            'tool' => $tool,
            'status' => 'open',
            'severity' => 'high',
            'summary' => $remaining.' vulnerabilities found',
            'remaining_count' => $remaining,
            'detected_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_digest_sends_single_email_covering_multiple_projects(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $projectOne = Project::factory()->create(['user_id' => $user->id, 'name' => 'Alpha Site']);
        $projectTwo = Project::factory()->create(['user_id' => $user->id, 'name' => 'Beta Site']);

        $this->makeOpenIssue($projectOne, 'npm', 3);
        $this->makeOpenIssue($projectTwo, 'composer', 2);

        $sentBodies = [];
        Mail::shouldReceive('raw')
            ->once()
            ->andReturnUsing(function (string $body) use (&$sentBodies) {
                $sentBodies[] = $body;
            });

        app(AuditService::class)->sendPendingDigest();

        $this->assertCount(1, $sentBodies);
        $this->assertStringContainsString('Alpha Site', $sentBodies[0]);
        $this->assertStringContainsString('Beta Site', $sentBodies[0]);
    }

    public function test_digest_skips_issues_with_pending_automated_fix(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->makeOpenIssue($project, 'npm', 3);

        DeploymentQueueItem::create([
            'project_id' => $project->id,
            'action' => 'npm_audit_fix',
            'status' => 'queued',
            'position' => 1,
            'payload' => ['reason' => 'audit_fix'],
        ]);

        Mail::shouldReceive('raw')->never();

        app(AuditService::class)->sendPendingDigest();
    }

    public function test_digest_respects_email_cooldown(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $issue = $this->makeOpenIssue($project, 'npm', 3);
        $issue->last_emailed_at = now()->subHours(1);
        $issue->save();

        Mail::shouldReceive('raw')->never();

        app(AuditService::class)->sendPendingDigest();
    }

    public function test_digest_stamps_emailed_issues(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $project = Project::factory()->create(['user_id' => $user->id]);

        $issue = $this->makeOpenIssue($project, 'npm', 3);

        Mail::shouldReceive('raw')->once();

        app(AuditService::class)->sendPendingDigest();

        $this->assertNotNull($issue->fresh()->last_emailed_at);
    }
}
