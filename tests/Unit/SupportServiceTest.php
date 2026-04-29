<?php

namespace Tests\Unit;

use App\Services\LicenseService;
use App\Services\SupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_ticket_posts_support_headers_and_checks_local_tls_bypass_setting(): void
    {
        config([
            'app.url' => 'http://localhost',
            'app.name' => 'Git Web Manager',
            'gitmanager.support.enabled' => true,
            'gitmanager.support.api_url' => 'https://gitwebmanager.com/api/v1/support',
        ]);

        $installationUuid = '0b4f65d5-4d7b-4d2e-82cf-9f975770ca0c';

        $this->mock(LicenseService::class, function ($mock) use ($installationUuid) {
            $mock->shouldReceive('supportAuthContext')
                ->once()
                ->withNoArgs()
                ->andReturn([
                    'license_key' => 'gwm_TESTKEY123',
                    'installation_uuid' => $installationUuid,
                    'app_url' => 'http://localhost',
                    'app_name' => 'Git Web Manager',
                    'public_ip' => null,
                ]);

            $mock->shouldReceive('allowInsecureLocalTlsForEndpoint')
                ->once()
                ->with('https://gitwebmanager.com/api/v1/support/tickets')
                ->andReturn(true);
        });

        Http::fake([
            'https://gitwebmanager.com/api/v1/support/tickets' => Http::response([
                'ticket' => [
                    'id' => 42,
                    'subject' => 'Deploy failure',
                    'status' => 'open',
                ],
            ], 200),
        ]);

        $ticket = app(SupportService::class)->createTicket(
            'Deploy failure',
            'Timeout after npm install',
            'high'
        );

        $this->assertSame(42, $ticket['id']);

        Http::assertSent(function ($request) use ($installationUuid) {
            return $request->url() === 'https://gitwebmanager.com/api/v1/support/tickets'
                && $request->method() === 'POST'
                && $request['subject'] === 'Deploy failure'
                && $request['priority'] === 'high'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('gwm_TESTKEY123:'.$installationUuid))
                && $request->hasHeader('X-GWM-License-Key', 'gwm_TESTKEY123')
                && $request->hasHeader('X-GWM-License-Installation', $installationUuid)
                && $request->hasHeader('X-GWM-App-Url', 'http://localhost')
                && $request->hasHeader('X-GWM-App-Name', 'Git Web Manager');
        });
    }

    public function test_create_ticket_formats_local_tls_error_message(): void
    {
        config([
            'gitmanager.support.enabled' => true,
            'gitmanager.support.api_url' => 'https://gitwebmanager.com/api/v1/support',
        ]);

        $this->mock(LicenseService::class, function ($mock) {
            $mock->shouldReceive('supportAuthContext')
                ->once()
                ->withNoArgs()
                ->andReturn([
                    'license_key' => 'gwm_TESTKEY123',
                    'installation_uuid' => '0b4f65d5-4d7b-4d2e-82cf-9f975770ca0c',
                    'app_url' => 'http://localhost',
                    'app_name' => 'Git Web Manager',
                    'public_ip' => null,
                ]);

            $mock->shouldReceive('allowInsecureLocalTlsForEndpoint')
                ->once()
                ->with('https://gitwebmanager.com/api/v1/support/tickets')
                ->andReturn(false);
        });

        Http::fake([
            'https://gitwebmanager.com/api/v1/support/tickets' => static function (): never {
                throw new \Exception('cURL error 60: SSL certificate problem: unable to get local issuer certificate');
            },
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('System -> Edition & License');

        app(SupportService::class)->createTicket(
            'Deploy failure',
            'Timeout after npm install',
            'high'
        );
    }
}
