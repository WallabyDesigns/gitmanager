<?php

namespace App\Livewire\Users;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Component;

class Index extends Component
{
    public string $tab = 'list';

    public array $userForm = [
        'name' => '',
        'email' => '',
        'password' => '',
        'require_password_change' => true,
    ];

    public ?string $generatedPassword = null;

    public ?int $generatedUserId = null;

    public function render()
    {
        $settings = app(SettingsService::class);

        return view('livewire.users.index', [
            'users' => User::query()->orderBy('created_at')->get(),
            'mailConfigured' => $settings->isMailConfigured(),
            'showMailSettingsLink' => auth()->user()?->isAdmin() ?? false,
        ])->layout('layouts.app', [
            'title' => 'Users',
            'header' => view('livewire.users.partials.header'),
        ]);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'list' ? 'list' : 'create';
    }

    public function createUser(): void
    {
        $validated = $this->validate([
            'userForm.name' => ['required', 'string', 'max:255'],
            'userForm.email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'userForm.password' => ['nullable', 'string', 'min:12'],
            'userForm.require_password_change' => ['boolean'],
        ]);

        $password = $validated['userForm']['password'] ?: Str::password(16);
        $admin = User::query()->where('id', 1)->first();

        $user = User::create([
            'name' => $validated['userForm']['name'],
            'email' => $validated['userForm']['email'],
            'password' => Hash::make($password),
            'must_change_password' => (bool) ($validated['userForm']['require_password_change'] ?? true),
            'timezone' => $admin?->timezone,
            'locale' => $admin?->locale ?? 'en',
        ]);

        $this->generatedPassword = $password;
        $this->generatedUserId = $user->id;

        $this->userForm['name'] = '';
        $this->userForm['email'] = '';
        $this->userForm['password'] = '';

        $this->tab = 'list';
        $this->dispatch('notify', message: 'User created.');
    }

    public function sendPasswordReset(int $userId): void
    {
        $user = User::findOrFail($userId);
        Password::sendResetLink(['email' => $user->email]);

        $this->dispatch('notify', message: 'Password reset link sent.');
    }

    public function resetPassword(int $userId): void
    {
        $user = User::findOrFail($userId);
        $password = Str::password(16);

        $user->update([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ]);

        $this->generatedPassword = $password;
        $this->generatedUserId = $user->id;

        $this->dispatch('notify', message: 'Temporary password generated.');
    }
}
