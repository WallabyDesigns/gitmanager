<?php

namespace Tests\Feature;

use App\Livewire\AppUpdates\Index as AppUpdatesIndex;
use App\Models\AppUpdate;
use App\Models\User;
use App\Services\SelfUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppUpdatesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_updates_page_loads_without_eager_rendering_large_historical_logs(): void
    {
        $admin = User::factory()->create();

        AppUpdate::query()->create([
            'status' => 'success',
            'from_hash' => 'aaa111',
            'to_hash' => 'bbb222',
            'output_log' => null,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        AppUpdate::query()->create([
            'status' => 'failed',
            'from_hash' => 'bbb222',
            'to_hash' => 'ccc333',
            'output_log' => str_repeat('historical-log-', 120000).'older-log-marker',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->get('/system')
            ->assertOk()
            ->assertSee('Latest Update')
            ->assertSee('Recent Updates')
            ->assertDontSee('older-log-marker');
    }

    public function test_recent_update_log_is_loaded_on_demand(): void
    {
        $admin = User::factory()->create();

        AppUpdate::query()->create([
            'status' => 'success',
            'from_hash' => 'aaa111',
            'to_hash' => 'bbb222',
            'output_log' => null,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $historical = AppUpdate::query()->create([
            'status' => 'failed',
            'from_hash' => 'bbb222',
            'to_hash' => 'ccc333',
            'output_log' => str_repeat('historical-log-', 6000).'older-log-marker',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);

        Livewire::actingAs($admin)
            ->test(AppUpdatesIndex::class)
            ->assertDontSee('older-log-marker')
            ->call('toggleUpdateLog', $historical->id)
            ->assertSet('expandedUpdateId', $historical->id)
            ->assertSee('older-log-marker');
    }

    public function test_run_update_starts_background_process_instead_of_running_inline(): void
    {
        $admin = User::factory()->create();

        $this->mock(SelfUpdateService::class, function ($mock) use ($admin): void {
            $mock->shouldReceive('getUpdateStatus')
                ->twice()
                ->andReturn(['status' => 'update-available', 'update_allowed' => true]);
            $mock->shouldReceive('getPendingChanges')
                ->twice()
                ->andReturn([]);
            $mock->shouldReceive('startUpdateInBackground')
                ->once()
                ->with(\Mockery::on(fn ($user) => $user?->is($admin)))
                ->andReturn(['ok' => true, 'message' => 'Update started in the background.']);
            $mock->shouldNotReceive('updateSmart');
        });

        Livewire::actingAs($admin)
            ->test(AppUpdatesIndex::class)
            ->call('runUpdate');
    }

    public function test_update_button_uses_update_allowed_status_flag(): void
    {
        $admin = User::factory()->create();

        $this->mock(SelfUpdateService::class, function ($mock): void {
            $mock->shouldReceive('getUpdateStatus')
                ->once()
                ->andReturn([
                    'status' => 'update-available',
                    'current' => 'aaa111',
                    'latest' => 'bbb222',
                    'update_allowed' => true,
                ]);
            $mock->shouldReceive('getPendingChanges')
                ->once()
                ->andReturn([
                    ['hash' => 'bbb222', 'subject' => 'Release update'],
                ]);
        });

        Livewire::actingAs($admin)
            ->test(AppUpdatesIndex::class)
            ->assertSeeHtml('wire:click="runUpdate"')
            ->assertDontSeeHtml('wire:click="runUpdate" wire:loading.attr="disabled" disabled');
    }
}
