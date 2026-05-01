<?php

namespace Tests\Unit;

use App\Services\LicenseService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LicenseService
    {
        return app(LicenseService::class);
    }

    public function test_key_configured_returns_false_when_no_key(): void
    {
        $this->assertFalse($this->service()->keyConfigured());
    }

    public function test_set_license_key_marks_as_configured(): void
    {
        $svc = $this->service();
        $svc->setLicenseKey('gwm_TESTKEY123');

        $this->assertTrue($svc->keyConfigured());
    }

    public function test_set_license_key_ignores_blank_strings(): void
    {
        $svc = $this->service();
        $svc->setLicenseKey('   ');

        $this->assertFalse($svc->keyConfigured());
    }

    public function test_clear_license_removes_key_and_resets_state(): void
    {
        $svc = $this->service();
        $svc->setLicenseKey('gwm_TESTKEY123');
        $svc->clearLicense();

        $this->assertFalse($svc->keyConfigured());
        $state = $svc->state();
        $this->assertSame('missing', $state['status']);
        $this->assertSame('community', $state['edition']);
    }

    public function test_installation_uuid_is_generated_and_persisted(): void
    {
        $svc = $this->service();
        $uuid1 = $svc->installationUuid();
        $uuid2 = $svc->installationUuid();

        $this->assertTrue(Str::isUuid($uuid1));
        $this->assertSame($uuid1, $uuid2);
    }

    public function test_verify_now_returns_invalid_when_no_key(): void
    {
        $state = $this->service()->verifyNow();

        $this->assertSame('missing', $state['status']);
        $this->assertFalse($this->service()->hasValidEnterpriseLicense());
    }

    public function test_verify_now_returns_invalid_when_server_rejects(): void
    {
        Http::fake(['*' => Http::response(['valid' => false, 'message' => 'Invalid key.'], 422)]);

        $svc = $this->service();
        $svc->setLicenseKey('gwm_TESTKEY123');
        $state = $svc->verifyNow();

        $this->assertSame('invalid', $state['status']);
    }

    public function test_verify_now_returns_valid_when_server_confirms(): void
    {
        Http::fake(['*' => Http::response([
            'valid' => true,
            'message' => 'License validated.',
            'edition' => 'enterprise',
            'installation_uuid' => app(LicenseService::class)->installationUuid(),
        ], 200)]);

        $svc = $this->service();
        $svc->setLicenseKey('gwm_TESTKEY123');
        $state = $svc->verifyNow();

        $this->assertSame('valid', $state['status']);
        $this->assertSame('enterprise', $state['edition']);
        $this->assertTrue($svc->hasValidEnterpriseLicense());
    }

    public function test_verify_now_strips_html_from_server_message(): void
    {
        Http::fake(['*' => Http::response([
            'valid' => false,
            'message' => '<b>Bad</b> key <script>alert(1)</script>',
        ], 200)]);

        $svc = $this->service();
        $svc->setLicenseKey('gwm_TESTKEY123');
        $state = $svc->verifyNow();

        $this->assertStringNotContainsString('<b>', $state['message']);
        $this->assertStringNotContainsString('<script>', $state['message']);
        $this->assertStringContainsString('Bad', $state['message']);
    }

    public function test_verify_now_returns_invalid_for_connection_failure(): void
    {
        Http::fake(['*' => fn () => throw new \Exception('Connection refused')]);

        $svc = $this->service();
        $svc->setLicenseKey('gwm_TESTKEY123');
        $state = $svc->verifyNow();

        $this->assertSame('invalid', $state['status']);
        $this->assertStringContainsString('Connection refused', $state['message']);
    }

    public function test_has_valid_enterprise_license_returns_false_with_no_key(): void
    {
        $this->assertFalse($this->service()->hasValidEnterpriseLicense());
    }

    public function test_mark_invalid_sets_correct_state(): void
    {
        $svc = $this->service();
        $svc->setLicenseKey('gwm_TESTKEY123');
        $svc->markInvalid('Custom error message.');

        $state = $svc->state();
        $this->assertSame('invalid', $state['status']);
        $this->assertSame('Custom error message.', $state['message']);
        $this->assertSame('community', $state['edition']);
    }

    public function test_state_returns_structured_array(): void
    {
        $state = $this->service()->state();

        $this->assertArrayHasKey('configured', $state);
        $this->assertArrayHasKey('status', $state);
        $this->assertArrayHasKey('message', $state);
        $this->assertArrayHasKey('edition', $state);
        $this->assertArrayHasKey('installation_uuid', $state);
        $this->assertArrayHasKey('bound_ip', $state);
        $this->assertArrayHasKey('detected_ip', $state);
        $this->assertArrayHasKey('verified_at', $state);
        $this->assertArrayHasKey('expires_at', $state);
        $this->assertArrayHasKey('grace_ends_at', $state);
    }

    public function test_support_auth_context_returns_correct_keys(): void
    {
        $context = $this->service()->supportAuthContext();

        $this->assertArrayHasKey('license_key', $context);
        $this->assertArrayHasKey('installation_uuid', $context);
        $this->assertArrayHasKey('app_url', $context);
        $this->assertArrayHasKey('app_name', $context);
        $this->assertArrayHasKey('public_ip', $context);
    }

    public function test_local_tls_bypass_stored_setting_overrides_enterprise_config(): void
    {
        // The enterprise LicenseRuntimeConfig::allowInsecureLocalTls() may return false
        // as its production-safe default. The stored DB setting (written by the repair
        // button) must win so the button actually works on local installs.
        Http::fake(['*' => Http::response(['valid' => false, 'message' => 'Signature required.'], 401)]);

        $settings = app(SettingsService::class);
        $settings->set('system.license.allow_insecure_local_tls', true);

        $svc = $this->service();
        $svc->setLicenseKey('gwm_TESTKEY123');

        // verifyNow() must send at least one request (not short-circuit on TLS policy),
        // proving the bypass flag was read and honoured.
        $state = $svc->verifyNow();

        Http::assertSent(fn ($request) => true);
        $this->assertSame('invalid', $state['status']);
    }
}
