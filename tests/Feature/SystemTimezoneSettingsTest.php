<?php

namespace Tests\Feature;

use App\Livewire\System\Settings as SystemSettings;
use App\Models\User;
use App\Services\EditionService;
use App\Services\EnvBackupService;
use App\Services\EnvManagerService;
use App\Services\LicenseService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemTimezoneSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_timezone_defaults_to_utc_when_stored_value_is_missing_or_invalid(): void
    {
        User::factory()->create(['id' => 1, 'timezone' => null]);
        app(SettingsService::class)->set('system.timezone', 'Not/A_Real_Timezone');

        $component = new SystemSettings;
        $component->loadData(
            app(EditionService::class),
            app(SettingsService::class),
            app(LicenseService::class),
            app(EnvManagerService::class),
            app(EnvBackupService::class),
        );

        $this->assertSame('UTC', $component->timezone);
        $this->assertSame('UTC', $component->timezones[0]);
    }

    public function test_application_timezone_populates_saved_value(): void
    {
        User::factory()->create(['id' => 1, 'timezone' => 'America/New_York']);
        app(SettingsService::class)->set('system.timezone', 'America/Chicago');

        $component = new SystemSettings;
        $component->mount();

        $this->assertSame('America/Chicago', $component->timezone);
        $this->assertContains('America/Chicago', $component->timezones);
    }
}
