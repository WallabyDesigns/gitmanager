<?php

namespace Tests\Feature;

use App\Models\AppUpdate;
use App\Models\User;
use App\Services\SelfUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfUpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create(); // id=1 → isAdmin() returns true
    }

    private function nonAdminUser(): User
    {
        return User::factory()->create(); // id>1
    }

    private function fakeUpdate(string $status = 'completed'): AppUpdate
    {
        $update = new AppUpdate([
            'status' => $status,
            'from_hash' => 'abc1234',
            'to_hash' => 'def5678',
            'output_log' => 'Update log output.',
            'triggered_by' => null,
        ]);
        $update->started_at = now();
        $update->finished_at = now();

        return $update;
    }

    public function test_update_requires_authentication(): void
    {
        $this->get('/update')->assertRedirect('/login');
    }

    public function test_update_requires_admin(): void
    {
        $this->adminUser(); // ensures id=1 exists
        $user = $this->nonAdminUser();

        $this->actingAs($user)->get('/update')->assertForbidden();
    }

    public function test_update_returns_plain_text_response(): void
    {
        AppUpdate::query()->create([
            'status' => 'running',
            'action' => 'self_update',
            'from_hash' => 'abc1234',
            'to_hash' => null,
            'output_log' => "PHP Warning: noisy\nUpdate log output.",
            'started_at' => now(),
        ]);

        $this->mock(SelfUpdateService::class, function ($mock) {
            $mock->shouldNotReceive('startUpdateInBackground');
            $mock->shouldNotReceive('updateSmart');
        });

        $response = $this->actingAs($this->adminUser())->get('/update');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Refresh', '2')
            ->assertSeeText('Update launch: started')
            ->assertSeeText('A self-update is already running.')
            ->assertSeeText('Update status: running')
            ->assertSeeText('Update log output.')
            ->assertDontSeeText('PHP Warning: noisy');
    }

    public function test_update_starts_background_process_when_no_update_is_running(): void
    {
        $this->mock(SelfUpdateService::class, function ($mock) {
            $mock->shouldReceive('startUpdateInBackground')
                ->once()
                ->with(\Mockery::any())
                ->andReturn(['ok' => true, 'message' => 'Update started in the background.']);
        });

        $response = $this->actingAs($this->adminUser())->get('/update');

        $response->assertOk()
            ->assertHeader('Refresh', '2')
            ->assertSeeText('Update launch: started')
            ->assertSeeText('Update started in the background.')
            ->assertSeeText('Update status: starting')
            ->assertSeeText('Waiting for the background updater to create its log entry...');
    }

    public function test_update_does_not_show_previous_completed_log_while_new_background_update_is_starting(): void
    {
        AppUpdate::query()->create([
            'status' => 'failed',
            'action' => 'self_update',
            'from_hash' => 'old111',
            'to_hash' => 'old222',
            'output_log' => 'Previous failed update output.',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);

        $this->mock(SelfUpdateService::class, function ($mock) {
            $mock->shouldReceive('startUpdateInBackground')
                ->once()
                ->with(\Mockery::any())
                ->andReturn(['ok' => true, 'message' => 'Update started in the background.']);
        });

        $response = $this->actingAs($this->adminUser())->get('/update');

        $response->assertOk()
            ->assertHeader('Refresh', '2')
            ->assertSeeText('Update launch: started')
            ->assertSeeText('Update status: starting')
            ->assertSeeText('Waiting for the background updater to create its log entry...')
            ->assertDontSeeText('Previous failed update output.')
            ->assertDontSeeText('Update status: failed');
    }

    public function test_rollback_requires_authentication(): void
    {
        $this->get('/rollback')->assertRedirect('/login');
    }

    public function test_rollback_requires_admin(): void
    {
        $this->adminUser();
        $user = $this->nonAdminUser();

        $this->actingAs($user)->get('/rollback')->assertForbidden();
    }

    public function test_rollback_returns_plain_text_response(): void
    {
        $update = $this->fakeUpdate('rolled_back');

        $this->mock(SelfUpdateService::class, function ($mock) use ($update) {
            $mock->shouldReceive('rollback')->once()->with(\Mockery::any(), null)->andReturn($update);
        });

        $response = $this->actingAs($this->adminUser())->get('/rollback');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('Rollback status: rolled_back');
    }

    public function test_rollback_passes_hash_query_param_to_service(): void
    {
        $update = $this->fakeUpdate('rolled_back');

        $this->mock(SelfUpdateService::class, function ($mock) use ($update) {
            $mock->shouldReceive('rollback')->once()->with(\Mockery::any(), 'abc1234')->andReturn($update);
        });

        $this->actingAs($this->adminUser())->get('/rollback?hash=abc1234')->assertOk();
    }
}
