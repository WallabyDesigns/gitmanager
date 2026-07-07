<?php

namespace Tests\Feature;

use App\Livewire\System\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SecuritySettingsShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_settings_uses_security_shell_not_system_sidebar(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->get('/security/settings');
        $response->assertOk();

        $html = $response->getContent();
        // Security tab bar present
        $this->assertStringContainsString('Security navigation', $html);
        // System sidebar description absent
        $this->assertStringNotContainsString('Updates, security, settings, and platform services.', $html);
    }

    /**
     * Regression test: the shell used to be derived from request()->routeIs()
     * directly in the Blade view. That works on the initial GET, but flips to
     * false on the Livewire follow-up request triggered by
     * wire:init="loadData" (a separate request to Livewire's own update
     * endpoint, not the security.settings route), causing the page to flash
     * from the Security shell to the System sidebar shell right after load.
     *
     * Livewire::test() mounts the component outside the security.settings
     * route context, so request()->routeIs('security.settings') is false
     * here — the same condition the real loadData round-trip hits. Setting
     * $securityShell explicitly (as mount() would have from the real route)
     * and asserting the Security shell still renders proves the shell is
     * driven by the persisted property, not re-derived from the request.
     */
    public function test_security_shell_survives_the_loaddata_round_trip(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->set('securityShell', true)
            ->call('loadData')
            ->assertSee('Security navigation', false)
            ->assertDontSee('Updates, security, settings, and platform services.');
    }

    /**
     * Regression test: system/settings.blade.php included the shared
     * security tabs partial without passing ['securityTab' => 'settings'],
     * so the partial's default ('security') always won and the Settings
     * tab never showed as active — even while on /security/settings.
     */
    public function test_settings_tab_is_active_on_security_settings_page(): void
    {
        $admin = User::factory()->create();

        $html = $this->actingAs($admin)->get('/security/settings')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<a\s+href="[^"]*/security"\s+class="px-3 py-2 text-sm border-b-2 -mb-px border-transparent text-slate-400 hover:text-slate-200">#',
            $html,
            'Expected the Security tab to render as idle while on /security/settings.'
        );

        $this->assertMatchesRegularExpression(
            '#<a\s+href="[^"]*/security/settings"\s+class="px-3 py-2 text-sm border-b-2 -mb-px border-indigo-500 text-white">#',
            $html,
            'Expected the Settings tab to render as active while on /security/settings.'
        );
    }
}
