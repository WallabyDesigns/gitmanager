<?php

namespace Tests\Feature;

use App\Mail\SystemNotificationMail;
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

        Mail::fake();

        $this->assertSame(1, $digest->sendDueDigests());
        Mail::assertSent(SystemNotificationMail::class, function (SystemNotificationMail $mail): bool {
            $this->assertCount(2, $mail->items);
            $this->assertSame('Deployment failed for Alpha.', $mail->items[0]['title']);
            $this->assertSame('Queued deploy task failed for Alpha.', $mail->items[1]['title']);

            return true;
        });
        $this->assertSame(2, EmailDigestEntry::query()->whereNotNull('sent_at')->count());
    }

    public function test_digest_cooldown_defers_new_events_for_the_same_recipients(): void
    {
        $digest = app(EmailDigestService::class);
        $digest->queue('deploy-1', 'deployment', ['ops@example.com'], 'First failure.');

        Mail::fake();
        $this->assertSame(1, $digest->sendDueDigests());

        $digest->queue('deploy-2', 'deployment', ['ops@example.com'], 'Second failure.');
        Mail::fake();
        $this->assertSame(0, $digest->sendDueDigests());
        Mail::assertNothingSent();
        $this->assertSame(1, EmailDigestEntry::query()->whereNull('sent_at')->count());
    }

    public function test_digest_consolidates_deployment_and_queue_failure_for_the_same_deployment(): void
    {
        $digest = app(EmailDigestService::class);
        $digest->queue('deployment-5', 'deployment', ['ops@example.com'], 'Deployment failed for Alpha.', [
            'deployment_id' => 5,
            'project' => 'Alpha',
        ]);
        $digest->queue('queue-5', 'queue_failure', ['ops@example.com'], 'Queued deployment task failed for Alpha.', [
            'deployment_id' => 5,
            'project' => 'Alpha',
            'output' => 'Composer install failed.',
        ]);

        Mail::fake();

        $this->assertSame(1, $digest->sendDueDigests());
        Mail::assertSent(SystemNotificationMail::class, function (SystemNotificationMail $mail): bool {
            $this->assertCount(1, $mail->items);
            $this->assertSame('Queued deployment task failed for Alpha.', $mail->items[0]['title']);
            $this->assertSame('Composer install failed.', $mail->items[0]['error_log']);

            return true;
        });
        $this->assertSame(2, EmailDigestEntry::query()->whereNotNull('sent_at')->count());
    }
}
