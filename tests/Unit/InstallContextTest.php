<?php

namespace Tests\Unit;

use App\Support\InstallContext;
use Tests\TestCase;

class InstallContextTest extends TestCase
{
    public function test_public_domain_is_not_treated_as_local_even_with_local_environment(): void
    {
        $this->assertFalse(
            InstallContext::isLocalInstall('https://admin.3dfinancial.org', 'local')
        );
    }

    public function test_local_domains_and_loopback_hosts_are_treated_as_local(): void
    {
        $this->assertTrue(InstallContext::isLocalInstall('http://localhost', 'production'));
        $this->assertTrue(InstallContext::isLocalInstall('https://demo.test', 'production'));
        $this->assertTrue(InstallContext::isLocalInstall('http://127.0.0.1', 'production'));
    }
}
