<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TurnstileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TurnstileLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_without_turnstile_token_when_captcha_is_disabled(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('projects.index', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_login_fails_with_wrong_password_when_captcha_is_disabled(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password');

        $component->call('login');

        $component
            ->assertHasErrors(['form.email'])
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_login_is_blocked_when_captcha_enabled_and_token_is_invalid(): void
    {
        $this->mock(TurnstileService::class, function ($mock): void {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('siteKey')->andReturn('test-site-key');
            $mock->shouldReceive('verify')->once()->andReturn(false);
        });

        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('form.turnstileToken', 'bad-token');

        $component->call('login');

        $component
            ->assertHasErrors(['form.turnstileToken'])
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_login_is_blocked_when_captcha_enabled_and_token_is_empty(): void
    {
        $this->mock(TurnstileService::class, function ($mock): void {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('siteKey')->andReturn('test-site-key');
            $mock->shouldReceive('verify')->once()->andReturn(false);
        });

        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('form.turnstileToken', '');

        $component->call('login');

        $component
            ->assertHasErrors(['form.turnstileToken'])
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_login_succeeds_when_captcha_enabled_and_token_is_valid(): void
    {
        $this->mock(TurnstileService::class, function ($mock): void {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('siteKey')->andReturn('test-site-key');
            $mock->shouldReceive('verify')->once()->andReturn(true);
        });

        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('form.turnstileToken', 'valid-token');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('projects.index', absolute: false));

        $this->assertAuthenticated();
    }
}
