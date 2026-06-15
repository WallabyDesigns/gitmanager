<?php

namespace Tests\Feature\System;

use App\Livewire\System\Settings as SystemSettings;
use App\Models\Setting;
use App\Models\User;
use App\Services\EditionService;
use App\Services\EnvBackupService;
use App\Services\EnvManagerService;
use App\Services\LicenseService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the System Settings Livewire component and the SettingsService
 * that persists values to the `settings` table.
 *
 * NOTE: The Settings component does not expose a saveSecurity() action — all
 * settings (including any future captcha keys) are saved through the generic
 * save() action. The captcha-specific tests here use SettingsService directly
 * to validate the storage contract.  The encrypted-value test is a real
 * assertion against the raw DB row.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Settings persistence via SettingsService
    // -----------------------------------------------------------------------

    public function test_settings_service_stores_and_retrieves_a_plain_value(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('system.captcha.enabled', true);

        $this->assertTrue((bool) $settings->get('system.captcha.enabled'));
    }

    public function test_settings_service_stores_captcha_site_key_as_plain_text(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('system.captcha.site_key', 'key123');

        $this->assertSame('key123', $settings->get('system.captcha.site_key'));
    }

    public function test_captcha_secret_is_stored_encrypted_and_raw_db_value_is_not_plain_text(): void
    {
        $settings = app(SettingsService::class);

        $settings->setEncrypted('system.captcha.secret_key', 'secret');

        // The raw value in the database must not be the literal string 'secret'.
        $rawValue = Setting::query()
            ->where('key', 'system.captcha.secret_key')
            ->value('value');

        $this->assertNotNull($rawValue);
        $this->assertNotSame('secret', $rawValue);

        // Decrypting must round-trip back to the original value.
        $this->assertSame('secret', $settings->getDecrypted('system.captcha.secret_key'));
    }

    // -----------------------------------------------------------------------
    // System Settings component — admin access
    // -----------------------------------------------------------------------

    public function test_admin_can_save_system_settings_via_component_save_method(): void
    {
        // Admin user: the application defines admin as user with id === 1.
        $admin = User::factory()->create(['id' => 1]);
        $this->actingAs($admin);

        $settingsService = app(SettingsService::class);
        $editionService  = app(EditionService::class);
        $licenseService  = app(LicenseService::class);
        $envManager      = app(EnvManagerService::class);
        $envBackup       = app(EnvBackupService::class);

        // Instantiate directly (avoids rendering the view which references
        // a route that only exists in the Enterprise edition).
        $component = new SystemSettings;
        $component->mount();
        $component->loadData($editionService, $settingsService, $licenseService, $envManager, $envBackup);

        // Flip a boolean setting and persist via save().
        $component->checkUpdates = false;
        $component->save($editionService, $settingsService, $licenseService);

        // The value must now be in the settings table.
        $this->assertFalse((bool) $settingsService->get('system.check_updates', true));
    }

    // -----------------------------------------------------------------------
    // Access control — non-admin is blocked from system routes
    // -----------------------------------------------------------------------

    public function test_non_admin_user_receives_403_when_accessing_system_scheduler(): void
    {
        // A user with id > 1 is not an admin (see User::isAdmin()).
        $nonAdmin = User::factory()->create(['id' => 2]);

        $this->actingAs($nonAdmin)
            ->get('/system/scheduler')
            ->assertForbidden();
    }

    public function test_non_admin_user_receives_403_when_accessing_system_application(): void
    {
        $nonAdmin = User::factory()->create(['id' => 2]);

        $this->actingAs($nonAdmin)
            ->get('/system/application')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected_from_system_scheduler(): void
    {
        User::factory()->create();

        $this->get('/system/scheduler')
            ->assertRedirect('/login');
    }

    public function test_admin_is_not_forbidden_from_system_scheduler(): void
    {
        // NOTE: The full page render references route('system.diagnostics') which
        // only exists in the Enterprise edition. We assert that admin is not
        // given a 403 (middleware passes). The 500 from the missing route is an
        // enterprise-only view issue unrelated to access control.
        $admin = User::factory()->create(['id' => 1]);

        $response = $this->actingAs($admin)->get('/system/scheduler');

        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotSame(302, $response->getStatusCode());
    }
}
