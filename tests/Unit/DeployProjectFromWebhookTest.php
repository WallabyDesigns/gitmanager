<?php

namespace Tests\Unit;

use App\Jobs\DeployProjectFromWebhook;
use Tests\TestCase;

class DeployProjectFromWebhookTest extends TestCase
{
    public function test_webhook_job_timeout_defaults_from_deployment_process_timeout_with_buffer(): void
    {
        config([
            'gitmanager.deployments.process_timeout' => 1800,
            'gitmanager.deployments.job_timeout' => 0,
        ]);

        $job = new DeployProjectFromWebhook(123);

        $this->assertSame(2100, $job->timeout);
    }

    public function test_webhook_job_timeout_respects_explicit_override(): void
    {
        config([
            'gitmanager.deployments.process_timeout' => 1800,
            'gitmanager.deployments.job_timeout' => 2700,
        ]);

        $job = new DeployProjectFromWebhook(123);

        $this->assertSame(2700, $job->timeout);
    }
}
