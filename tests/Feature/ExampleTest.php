<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_register_when_no_users_exist(): void
    {
        $this->get('/')->assertRedirect('/register');
    }

    public function test_root_redirects_to_login_when_users_exist(): void
    {
        User::factory()->create();

        $this->get('/')->assertRedirect('/login');
    }

    public function test_root_redirects_authenticated_user_to_projects(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('projects.index'));
    }
}
