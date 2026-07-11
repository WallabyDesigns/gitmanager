<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use App\Services\EmailDigestService;
use Illuminate\Console\Command;

class SendEmailDigest extends Command
{
    protected $signature = 'notifications:send-digest';

    protected $description = 'Send the consolidated non-urgent email activity report.';

    public function handle(AuditService $audits, EmailDigestService $digest): int
    {
        $audits->sendPendingDigest();
        $sent = $digest->sendDueDigests();

        $this->info("Sent {$sent} email digest(s).");

        return self::SUCCESS;
    }
}
