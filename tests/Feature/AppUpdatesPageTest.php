<?php

namespace Tests\Feature;

use App\Livewire\AppUpdates\Index as AppUpdatesIndex;
use App\Models\AppUpdate;
use App\Models\User;
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
}
