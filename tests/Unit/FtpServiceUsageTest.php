<?php

namespace Tests\Unit;

use Tests\TestCase;

class FtpServiceUsageTest extends TestCase
{
    public function test_manages_remote_deployments_references_ftp_service_namespace(): void
    {
        $contents = file_get_contents(app_path('Services/Concerns/ManagesRemoteDeployments.php'));

        $this->assertNotFalse($contents, 'Unable to read ManagesRemoteDeployments.php');
        $this->assertStringContainsString(
            '\\App\\Services\\FtpService',
            $contents,
            'ManagesRemoteDeployments should reference the fully qualified FtpService class.'
        );
    }
}
