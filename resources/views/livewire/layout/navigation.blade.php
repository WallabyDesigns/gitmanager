<?php

use App\Livewire\Actions\Logout;
use App\Models\AppUpdate;
use App\Models\SecurityAlert;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public int $openAlerts = 0;

    public function mount(): void
    {
        $userId = Auth::id();
        $securityCount = $userId
            ? SecurityAlert::query()
                ->where('state', 'open')
                ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
                ->count()
            : 0;

        $latestUpdate = AppUpdate::query()->orderByDesc('started_at')->first();
        $updateIssueCount = $latestUpdate && $latestUpdate->status === 'failed' ? 1 : 0;

        $this->openAlerts = $securityCount + $updateIssueCount;
    }
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="relative z-50 bg-white/80 dark:bg-slate-900/80 border-b border-slate-200/70 dark:border-slate-800 backdrop-blur">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('projects.index') }}" wire:navigate>
                        <x-application-logo class="block h-9 w-auto fill-current text-slate-800 dark:text-slate-100" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('projects.create')" :active="request()->routeIs('projects.create')" wire:navigate>
                        {{ __('New Project') }}
                    </x-nav-link>
                    <x-nav-link :href="route('projects.index')" :active="request()->routeIs('projects.index', 'projects.show', 'projects.edit')" wire:navigate>
                        {{ __('Projects') }}
                    </x-nav-link>
                    <x-nav-link :href="route('app-updates.index')" :active="request()->routeIs('app-updates.index')" wire:navigate>
                        {{ __('Update App') }}
                    </x-nav-link>
                    <x-nav-link :href="route('security.index')" :active="request()->routeIs('security.index')" wire:navigate>
                        <span class="flex items-center gap-2">
                            {{ __('Security') }}
                            @if ($openAlerts > 0)
                                <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-xs text-rose-200">
                                    {{ $openAlerts }}
                                </span>
                            @endif
                        </span>
                    </x-nav-link>
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 gap-3">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-slate-500 bg-white/60 hover:text-slate-700 dark:bg-slate-900/60 dark:text-slate-300 dark:hover:text-slate-100 focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-2">
            <x-responsive-nav-link :href="route('projects.create')" :active="request()->routeIs('projects.create')" wire:navigate>
                {{ __('New Project') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('projects.index')" :active="request()->routeIs('projects.index', 'projects.show', 'projects.edit')" wire:navigate>
                {{ __('Projects') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('app-updates.index')" :active="request()->routeIs('app-updates.index')" wire:navigate>
                {{ __('Update App') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('security.index')" :active="request()->routeIs('security.index')" wire:navigate>
                <span class="flex items-center gap-2">
                    {{ __('Security') }}
                    @if ($openAlerts > 0)
                        <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-xs text-rose-200">
                            {{ $openAlerts }}
                        </span>
                    @endif
                </span>
            </x-responsive-nav-link>
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-slate-800 dark:text-slate-100" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-slate-500 dark:text-slate-400">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
