@php
    $outputLogTailChars = isset($outputLogTailChars) ? (int) $outputLogTailChars : 60000;
    $expandedUpdateId = $expandedUpdateId ?? null;
    $expandedUpdateLog = $expandedUpdateLog ?? null;
    $expandedUpdateLogTruncated = (bool) ($expandedUpdateLogTruncated ?? false);
    $tabLabel = fn (string $tab): string => match ($tab) {
        'dependencies' => __('App Dependencies'),
        'logs' => __('Debug Logs'),
        default => __('Update Status'),
    };
    $actionLabel = fn (?string $action): string => match ($action) {
        'self_update' => __('App Update'),
        'force_update' => __('Force Update'),
        'rollback' => __('Rollback'),
        'app_dependency_audit' => __('App Dependency Audit'),
        'app_composer_update' => __('App Composer Update'),
        'app_npm_update' => __('App Npm Update'),
        'app_npm_audit_fix' => __('App Npm Audit Fix'),
        'app_npm_audit_fix_force' => __('App Npm Audit Fix (Force)'),
        default => ucfirst(str_replace('_', ' ', (string) ($action ?: 'update'))),
    };
    $statusPill = fn (?string $status): string => match ($status) {
        'success' => 'bg-emerald-500/10 text-emerald-300',
        'warning', 'blocked' => 'bg-amber-500/10 text-amber-300',
        'failed' => 'bg-rose-500/10 text-rose-300',
        'running' => 'bg-indigo-500/10 text-indigo-300',
        default => 'bg-slate-800 text-slate-300',
    };
@endphp

<div class="py-10" wire:poll.10s>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs', ['systemTab' => 'updates'])

            <div class="min-w-0 space-y-6">
                <div>
                    <div class="sm:hidden relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                        <button
                            type="button"
                            @click="open = !open"
                            class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-700 bg-slate-900/60 px-4 py-2.5 text-sm text-slate-200 hover:border-slate-600 transition"
                            aria-haspopup="true"
                            :aria-expanded="open"
                        >
                            <span>{{ $tabLabel($activeTab) }}</span>
                            <svg class="h-4 w-4 text-slate-400 transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div
                            x-show="open"
                            x-cloak
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute left-0 right-0 top-full mt-1 z-50 rounded-lg border border-slate-700 bg-slate-900 shadow-lg overflow-hidden"
                            role="menu"
                        >
                            <button type="button" wire:click="setTab('status')" @click="open = false" class="flex w-full items-center px-4 py-3 text-sm transition {{ $activeTab === 'status' ? 'bg-indigo-500/10 font-medium' : 'text-slate-200 hover:bg-slate-800' }}" role="menuitem">
                                {{ __('Update Status') }}
                            </button>
                            <button type="button" wire:click="setTab('dependencies')" @click="open = false" class="flex w-full items-center px-4 py-3 text-sm transition border-t border-slate-800 {{ $activeTab === 'dependencies' ? 'bg-indigo-500/10 font-medium' : 'text-slate-200 hover:bg-slate-800' }}" role="menuitem">
                                {{ __('App Dependencies') }}
                            </button>
                            <button type="button" wire:click="setTab('logs')" @click="open = false" class="flex w-full items-center px-4 py-3 text-sm transition border-t border-slate-800 {{ $activeTab === 'logs' ? 'bg-indigo-500/10 font-medium' : 'text-slate-200 hover:bg-slate-800' }}" role="menuitem">
                                {{ __('Debug Logs') }}
                            </button>
                        </div>
                    </div>

                    <div class="hidden sm:flex flex-wrap gap-2 border-b border-slate-800">
                        <button type="button" wire:click="setTab('status')" class="px-3 py-2 text-sm border-b-2 {{ $activeTab === 'status' ? 'border-indigo-500' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
                            {{ __('Update Status') }}
                        </button>
                        <button type="button" wire:click="setTab('dependencies')" class="px-3 py-2 text-sm border-b-2 {{ $activeTab === 'dependencies' ? 'border-indigo-500' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
                            {{ __('App Dependencies') }}
                        </button>
                        <button type="button" wire:click="setTab('logs')" class="px-3 py-2 text-sm border-b-2 {{ $activeTab === 'logs' ? 'border-indigo-500' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
                            {{ __('Debug Logs') }}
                        </button>
                    </div>
                    <span class="sr-only">{{ __('Recent Updates') }}</span>
                </div>

                @if ($activeTab === 'status')
                    <div class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-100">{{ __('Update Status') }}</h3>
                                <p class="text-sm text-slate-400">{{ __('Current Git Web Manager version and update availability.') }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2 items-center">
                                @php($status = $updateStatus['status'] ?? 'unknown')
                                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $status === 'up-to-date' ? 'bg-emerald-500/20 text-emerald-200' : ($status === 'update-available' || $status === 'blocked' ? 'bg-amber-500/20 text-amber-200' : 'bg-slate-500/20 text-slate-200') }}">
                                    {{ strtoupper(str_replace('-', ' ', $status)) }}
                                </span>
                                <button type="button" wire:click="refreshUpdateStatus" wire:loading.attr="disabled" @if (! $checkUpdatesEnabled) disabled @endif class="px-3 py-1.5 rounded-md border text-sm border-slate-700 text-slate-300 hover:text-slate-100 disabled:opacity-60 inline-flex items-center">
                                    <x-loading-spinner target="refreshUpdateStatus" />
                                    {{ __('Check Now') }}
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
                            <div><div class="text-xs uppercase text-slate-500">{{ __('Current') }}</div><div class="font-mono text-slate-200">{{ $updateStatus['current'] ?? 'n/a' }}</div></div>
                            <div><div class="text-xs uppercase text-slate-500">{{ __('Latest') }}</div><div class="font-mono text-slate-200">{{ $updateStatus['latest'] ?? 'n/a' }}</div></div>
                            <div><div class="text-xs uppercase text-slate-500">{{ __('Branch') }}</div><div class="text-slate-200">{{ $updateStatus['branch'] ?? 'n/a' }}</div></div>
                            <div><div class="text-xs uppercase text-slate-500">{{ __('Checked') }}</div><div class="text-slate-200">{{ \App\Support\DateFormatter::forUser($updateStatus['checked_at'] ?? null, 'M j, Y g:i a', 'n/a') }}</div></div>
                        </div>

                        @if (! empty($updateStatus['error']))
                            <div class="mt-3 text-xs text-rose-400">{{ $updateStatus['error'] }}</div>
                        @endif
                    </div>

                    @if (! empty($pendingChanges))
                        <div class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="text-lg font-semibold text-slate-100">{{ __('Run Update') }}</h3>
                                    <p class="text-sm text-slate-400">{{ __('Start Git Web Manager updates in the background and monitor progress in the logs.') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="runUpdate" wire:loading.attr="disabled" @if (! $updateAllowed) disabled @endif class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 disabled:opacity-60 inline-flex items-center">
                                        <x-loading-spinner target="runUpdate" />
                                        {{ __('Update App') }}
                                    </button>
                                    <button type="button" wire:click="runForceUpdate" wire:loading.attr="disabled" onclick="return confirm('{{ __('Force update will discard local code changes and re-sync with the remote repo. Continue?') }}')" class="px-4 py-2 rounded-md border border-rose-500/70 text-rose-200 text-sm hover:text-white hover:bg-rose-500/10 inline-flex items-center">
                                        <x-loading-spinner target="runForceUpdate" />
                                        {{ __('Force Update') }}
                                    </button>
                                </div>
                            </div>
                            <details class="mt-4 rounded-lg border border-slate-800 bg-slate-950/40 p-4">
                                <summary class="cursor-pointer text-sm font-semibold text-slate-200">{{ __('Pending commits') }} ({{ count($pendingChanges) }})</summary>
                                <ul class="mt-3 space-y-2 text-xs">
                                    @foreach ($pendingChanges as $change)
                                        <li class="flex gap-2"><span class="font-mono text-slate-500">{{ $change['hash'] }}</span><span class="text-slate-200">{{ $change['subject'] }}</span></li>
                                    @endforeach
                                </ul>
                            </details>
                        </div>
                    @endif

                    <div class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
                        <h3 class="text-lg font-semibold text-slate-100">{{ __('Latest Update') }}</h3>
                        @include('livewire.app-updates.partials.log-card', ['update' => $latest, 'showOutput' => true])
                    </div>
                @endif

                @if ($activeTab === 'dependencies')
                    <div class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-100">{{ __('App Dependencies') }}</h3>
                                <p class="text-sm text-slate-400">{{ __('Audit and repair this Git Web Manager installation.') }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="auditAppDependencies" wire:loading.attr="disabled" class="px-3 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 inline-flex items-center"><x-loading-spinner target="auditAppDependencies" />{{ __('Audit App') }}</button>
                                <button type="button" wire:click="updateAppComposer" wire:loading.attr="disabled" class="px-3 py-2 rounded-md border text-sm border-slate-700 text-slate-200 hover:text-white inline-flex items-center"><x-loading-spinner target="updateAppComposer" />{{ __('Composer Update') }}</button>
                                <button type="button" wire:click="updateAppNpm" wire:loading.attr="disabled" class="px-3 py-2 rounded-md border text-sm border-slate-700 text-slate-200 hover:text-white inline-flex items-center"><x-loading-spinner target="updateAppNpm" />{{ __('Npm Update') }}</button>
                                <button type="button" wire:click="fixAppNpmAudit" wire:loading.attr="disabled" class="px-3 py-2 rounded-md border border-amber-400/70 text-sm text-amber-300 hover:text-amber-100 inline-flex items-center"><x-loading-spinner target="fixAppNpmAudit" />{{ __('Npm Audit Fix') }}</button>
                            </div>
                        </div>
                    </div>

                    <div class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
                        <h3 class="text-lg font-semibold text-slate-100">{{ __('Latest Dependency Log') }}</h3>
                        @include('livewire.app-updates.partials.log-card', ['update' => $latestDependencyLog, 'showOutput' => true])
                    </div>
                @endif

                @if ($activeTab === 'logs')
                    <div class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
                        <h3 class="text-lg font-semibold text-slate-100">{{ __('Debug Logs') }}</h3>
                        <div class="mt-4 space-y-3">
                            @forelse ($recent as $update)
                                @include('livewire.app-updates.partials.log-card', ['update' => $update, 'showOutput' => false])
                            @empty
                                <p class="text-sm text-slate-400">{{ __('No logs yet.') }}</p>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
