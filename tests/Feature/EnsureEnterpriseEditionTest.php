<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureEnterpriseEdition;
use App\Models\User;
use App\Services\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsureEnterpriseEditionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth', EnsureEnterpriseEdition::class])
            ->get('/test-enterprise-gate', fn () => response('ok'))
            ->name('test.enterprise.gate');
    }

    public function test_community_user_is_forbidden(): void
    {
        $this->mock(LicenseService::class, function ($mock) {
            $mock->shouldReceive('hasValidEnterpriseLicense')->andReturn(false);
        });

        $user = User::factory()->create();
        $this->actingAs($user)->get('/test-enterprise-gate')->assertForbidden();
    }

    public function test_enterprise_user_is_allowed(): void
    {
        $this->mock(LicenseService::class, function ($mock) {
            $mock->shouldReceive('hasValidEnterpriseLicense')->andReturn(true);
        });

        $user = User::factory()->create();
        $this->actingAs($user)->get('/test-enterprise-gate')->assertOk()->assertSeeText('ok');
    }

    public function test_unauthenticated_request_is_redirected_before_enterprise_check(): void
    {
        $this->mock(LicenseService::class, function ($mock) {
            $mock->shouldReceive('hasValidEnterpriseLicense')->never();
        });

        $this->get('/test-enterprise-gate')->assertRedirect('/login');
    }
}
