<?php

namespace Tests\Unit;

use Tests\TestCase;

class GitmanagerConfigTest extends TestCase
{
    public function test_php_binary_config_defaults_to_detected_binary_instead_of_system_php(): void
    {
        $this->assertNotSame('php', config('gitmanager.php_binary'));
    }
}
