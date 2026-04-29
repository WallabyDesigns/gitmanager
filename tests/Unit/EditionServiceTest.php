<?php

namespace Tests\Unit;

use App\Services\EditionService;
use App\Services\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EditionService
    {
        return app(EditionService::class);
    }

    public function test_current_returns_community_when_no_license(): void
    {
        $this->assertSame(EditionService::COMMUNITY, $this->service()->current());
    }

    public function test_current_returns_community_after_invalid_verify(): void
    {
        config(['gitmanager.license.verify_url' => '']);

        $license = app(LicenseService::class);
        $license->setLicenseKey('gwm_TESTKEY123');
        $license->verifyNow();

        $this->assertSame(EditionService::COMMUNITY, $this->service()->current());
    }

    public function test_label_for_community_edition(): void
    {
        $this->assertSame('Community Edition', $this->service()->label(EditionService::COMMUNITY));
    }

    public function test_label_for_enterprise_edition(): void
    {
        $this->assertSame('Enterprise Edition', $this->service()->label(EditionService::ENTERPRISE));
    }

    public function test_label_defaults_to_current_edition(): void
    {
        $label = $this->service()->label();
        $this->assertStringContainsString('Edition', $label);
    }

    public function test_current_ignores_stored_testing_override(): void
    {
        app(\App\Services\SettingsService::class)->set('system.testing.edition_override', EditionService::ENTERPRISE);

        $this->assertSame(EditionService::COMMUNITY, $this->service()->current());
    }
}
