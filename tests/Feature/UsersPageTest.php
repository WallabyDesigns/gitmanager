<?php

namespace Tests\Feature;

use App\Livewire\Users\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_sub_user_inherits_admin_locale_and_timezone(): void
    {
        $admin = User::factory()->create([
            'id' => 1,
            'locale' => 'ja',
            'timezone' => 'America/Chicago',
        ]);

        $this->actingAs($admin);

        Livewire::test(Index::class)
            ->set('userForm.name', 'Sub User')
            ->set('userForm.email', 'sub@example.com')
            ->set('userForm.password', 'temporary-password')
            ->call('createUser')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'sub@example.com',
            'locale' => 'ja',
            'timezone' => 'America/Chicago',
        ]);
    }
}
