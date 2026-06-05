<?php

namespace Tests\Feature;

use App\Models\AppUpdate;
use App\Services\SelfUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GitManagerSelfUpdateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_update_command_skips_when_no_update_is_available(): void
    {
        config()->set('gitmanager.self_update.enabled', true);

        $this->mock(SelfUpdateService::class, function ($mock): void {
            $mock->shouldReceive('getUpdateStatus')
                ->once()
                ->with(true)
                ->andReturn([
                    'status' => 'up-to-date',
                    'core_status' => 'up-to-date',
                    'enterprise_update_available' => false,
                ]);

            $mock->shouldReceive('updateSmart')->never();
            $mock->shouldReceive('forceUpdate')->never();
        });

        $this->artisan('gitmanager:self-update')
            ->expectsOutput('Self-update skipped: application is already up to date.')
            ->assertSuccessful();

        $this->assertSame(0, AppUpdate::query()->count());
    }

    public function test_self_update_command_runs_when_update_is_available(): void
    {
        config()->set('gitmanager.self_update.enabled', true);

        $update = new AppUpdate([
            'action' => 'self_update',
            'status' => 'success',
        ]);

        $this->mock(SelfUpdateService::class, function ($mock) use ($update): void {
            $mock->shouldReceive('getUpdateStatus')
                ->once()
                ->with(true)
                ->andReturn([
                    'status' => 'update-available',
                    'core_status' => 'update-available',
                ]);

            $mock->shouldReceive('updateSmart')
                ->once()
                ->with(null)
                ->andReturn($update);

            $mock->shouldReceive('forceUpdate')->never();
        });

        $this->artisan('gitmanager:self-update')
            ->assertSuccessful();
    }
}
