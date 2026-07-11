<?php

namespace Tests\Feature;

use App\Models\EmailDigestEntry;
use App\Services\EmailDigestService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailDigestServiceTest extends TestCase
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

        app(SettingsService::class)->set('system.email_digest_enabled', true);
        app(SettingsService::class)->set('system.email_digest_cooldown_minutes', 60);
    }

    public function test_digest_groups_multiple_events_for_the_same_recipients_into_one_email(): void
    {
        $digest = app(EmailDigestService::class);
        $digest->queue('deploy-1', 'deployment', ['ops@example.com'], 'Deployment failed for Alpha.');
        $digest->queue('queue-1', 'queue_failure', ['ops@example.com'], 'Queued deploy task failed for Alpha.');

        $bodies = [];
        Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $body) use (&$bodies): void {
            $bodies[] = $body;
        });

        $this->assertSame(1, $digest->sendDueDigests());
        $this->assertCount(1, $bodies);
        $this->assertStringContainsString('Deployment failed for Alpha.', $bodies[0]);
        $this->assertStringContainsString('Queued deploy task failed for Alpha.', $bodies[0]);
        $this->assertSame(2, EmailDigestEntry::query()->whereNotNull('sent_at')->count());
    }

    public function test_digest_cooldown_defers_new_events_for_the_same_recipients(): void
    {
        $digest = app(EmailDigestService::class);
        $digest->queue('deploy-1', 'deployment', ['ops@example.com'], 'First failure.');

        Mail::shouldReceive('raw')->once();
        $this->assertSame(1, $digest->sendDueDigests());

        $digest->queue('deploy-2', 'deployment', ['ops@example.com'], 'Second failure.');
        Mail::shouldReceive('raw')->never();

        $this->assertSame(0, $digest->sendDueDigests());
        $this->assertSame(1, EmailDigestEntry::query()->whereNull('sent_at')->count());
    }
}
