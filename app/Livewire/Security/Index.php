<?php

namespace App\Livewire\Security;

use App\Models\AppUpdate;
use App\Models\SecurityAlert;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url]
    public string $tab = 'current';
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
        $query = SecurityAlert::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
            ->with('project');

        $alerts = $this->tab === 'resolved'
            ? $query->where('state', '!=', 'open')
            : $query->where('state', 'open');

        $latestUpdate = AppUpdate::query()->orderByDesc('started_at')->first();

        return view('livewire.security.index', [
            'alerts' => $alerts->orderByDesc('alert_created_at')->get(),
            'users' => $this->tab === 'users'
                ? User::query()->orderBy('created_at')->get()
                : collect(),
            'openCount' => SecurityAlert::query()
                ->where('state', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                ->count(),
            'resolvedCount' => SecurityAlert::query()
                ->where('state', '!=', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', Auth::id()))
                ->count(),
            'appUpdateFailed' => $latestUpdate && $latestUpdate->status === 'failed',
            'latestUpdate' => $latestUpdate,
        ])->layout('layouts.app', [
            'header' => view('livewire.security.partials.header'),
        ]);
    }

    public function sync(): void
    {
        Artisan::call('security:sync');
        $this->dispatch('notify', message: 'Security alerts synced.');
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

        $user = User::create([
            'name' => $validated['userForm']['name'],
            'email' => $validated['userForm']['email'],
            'password' => Hash::make($password),
            'must_change_password' => (bool) ($validated['userForm']['require_password_change'] ?? true),
        ]);

        $this->generatedPassword = $password;
        $this->generatedUserId = $user->id;

        $this->userForm['name'] = '';
        $this->userForm['email'] = '';
        $this->userForm['password'] = '';

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
