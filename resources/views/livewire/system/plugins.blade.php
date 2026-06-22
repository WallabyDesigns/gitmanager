<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs', ['systemTab' => 'plugins'])

            <div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-100">{{ __('Plugins') }}</h1>
            <p class="mt-1 text-sm text-slate-400">{{ __('Manage built-in tools and runtime dependencies.') }}</p>
        </div>
        <button
            type="button"
            wire:click="checkUpdates"
            wire:loading.attr="disabled"
            class="shrink-0 inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg border border-slate-600 bg-slate-800 text-slate-200 hover:bg-slate-700 hover:border-slate-500 transition-colors disabled:opacity-50"
        >
            <svg wire:loading wire:target="checkUpdates" class="h-4 w-4 animate-spin text-slate-400" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg wire:loading.remove wire:target="checkUpdates" class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            {{ __('Check for Updates') }}
        </button>
    </div>

    {{-- Plugin Cards --}}
    @if (empty($pluginRecords))
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-8 text-center">
            <p class="text-slate-400 text-sm">{{ __('No plugins available.') }}</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($pluginRecords as $slug => $plugin)
                @php
                    $statusConfig = match($plugin['status']) {
                        'installed'     => ['label' => 'Installed',     'class' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20'],
                        'installing'    => ['label' => 'Installing…',   'class' => 'bg-indigo-500/10 text-indigo-300 border-indigo-500/20 animate-pulse'],
                        'updating'      => ['label' => 'Updating…',     'class' => 'bg-amber-500/10 text-amber-300 border-amber-500/20 animate-pulse'],
                        'error'         => ['label' => 'Error',         'class' => 'bg-rose-500/10 text-rose-300 border-rose-500/20'],
                        default         => ['label' => 'Not Installed', 'class' => 'bg-slate-500/10 text-slate-400 border-slate-600'],
                    };

                    $categoryConfig = match($plugin['category']) {
                        'runtime'          => ['label' => 'Runtime',          'class' => 'bg-violet-500/10 text-violet-300 border-violet-500/20'],
                        'process-manager'  => ['label' => 'Process Manager',  'class' => 'bg-cyan-500/10 text-cyan-300 border-cyan-500/20'],
                        default            => ['label' => ucfirst($plugin['category']), 'class' => 'bg-slate-500/10 text-slate-400 border-slate-600'],
                    };

                    $isInstalled  = $plugin['status'] === 'installed';
                    $isBusy       = in_array($plugin['status'], ['installing', 'updating']);
                    $updateAvail  = $isInstalled
                        && $plugin['latestVersion'] !== null
                        && $plugin['latestVersion'] !== $plugin['installedVersion'];
                @endphp

                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4 @if($plugin['status'] === 'error') border-rose-500/30 @elseif($updateAvail) border-amber-500/20 @endif">

                    {{-- Card Header --}}
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-800 border border-slate-700">
                                <svg class="h-5 w-5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 0 1-.657.643 48.39 48.39 0 0 1-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 0 1-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 0 0-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 0 1-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 0 0 .657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 0 1-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.401.604-.401.959v0c0 .333.277.599.61.58a48.1 48.1 0 0 0 5.427-.63 48.05 48.05 0 0 0 .582-4.717.532.532 0 0 0-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.036 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.959.401v0a.656.656 0 0 0 .658-.663 48.422 48.422 0 0 0-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 0 1-.61-.58v0Z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h2 class="text-base font-semibold text-slate-100">{{ $plugin['displayName'] }}</h2>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $categoryConfig['class'] }}">
                                        {{ $categoryConfig['label'] }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusConfig['class'] }}">
                                        {{ $statusConfig['label'] }}
                                    </span>
                                    @if ($updateAvail)
                                        <span class="inline-flex items-center rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-300">
                                            {{ __('Update Available') }}
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-slate-400">{{ $plugin['description'] }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Vulnerability Alerts --}}
                    @if (! empty($plugin['vulnerabilities']))
                        <div class="rounded-lg border border-rose-500/30 bg-rose-500/5 px-4 py-3 space-y-1">
                            <p class="text-xs font-semibold text-rose-300 uppercase tracking-wider">{{ __('Security Warnings') }}</p>
                            @foreach ($plugin['vulnerabilities'] as $vuln)
                                <p class="text-sm text-rose-200">{{ $vuln }}</p>
                            @endforeach
                        </div>
                    @endif

                    {{-- Error Message --}}
                    @if ($plugin['status'] === 'error' && isset($plugin['errorMessage']) && $plugin['errorMessage'])
                        <div class="rounded-lg border border-rose-500/30 bg-rose-500/5 px-4 py-3">
                            <p class="text-sm text-rose-200">{{ $plugin['errorMessage'] }}</p>
                        </div>
                    @endif

                    {{-- Version Info --}}
                    <div class="flex items-center gap-6 text-sm">
                        <div>
                            <span class="text-slate-500">{{ __('Installed') }}:</span>
                            <span class="ml-1 font-mono text-slate-300">
                                {{ $plugin['installedVersion'] ?? '—' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-slate-500">{{ __('Latest') }}:</span>
                            <span class="ml-1 font-mono {{ $updateAvail ? 'text-amber-300' : 'text-slate-300' }}">
                                @if ($plugin['latestVersion'])
                                    {{ $plugin['latestVersion'] }}
                                @else
                                    <span class="text-slate-600 font-sans text-xs">{{ __('— click "Check for Updates"') }}</span>
                                @endif
                            </span>
                        </div>
                        @if ($plugin['lastCheckedAt'])
                            <div class="text-slate-600 text-xs">
                                {{ __('Checked') }} {{ $plugin['lastCheckedAt'] }}
                            </div>
                        @endif
                    </div>

                    {{-- Actions + Auto-update --}}
                    <div class="flex items-center justify-between gap-4 flex-wrap border-t border-slate-800 pt-4">

                        {{-- Action Buttons --}}
                        <div class="flex items-center gap-2 flex-wrap">
                            @if (! $isInstalled && ! $isBusy)
                                <button
                                    type="button"
                                    wire:click="install('{{ $slug }}')"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10 hover:border-emerald-400 transition-colors disabled:opacity-50"
                                >
                                    <svg wire:loading wire:target="install('{{ $slug }}')" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    {{ __('Install') }}
                                </button>
                            @endif

                            @if ($isInstalled && $updateAvail && ! $isBusy)
                                <button
                                    type="button"
                                    wire:click="update('{{ $slug }}')"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-amber-500/40 text-amber-300 hover:bg-amber-500/10 hover:border-amber-400 transition-colors disabled:opacity-50"
                                >
                                    <svg wire:loading wire:target="update('{{ $slug }}')" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    {{ __('Update') }}
                                </button>
                            @endif

                            @if ($isInstalled && ! $isBusy)
                                <button
                                    type="button"
                                    wire:click="uninstall('{{ $slug }}')"
                                    wire:confirm="{{ __('Are you sure you want to uninstall this plugin?') }}"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-rose-500/40 text-rose-300 hover:bg-rose-500/10 hover:border-rose-400 transition-colors disabled:opacity-50"
                                >
                                    <svg wire:loading wire:target="uninstall('{{ $slug }}')" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    {{ __('Uninstall') }}
                                </button>
                            @endif

                            @if ($isBusy)
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs text-slate-400">
                                    <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    {{ $plugin['status'] === 'installing' ? __('Installing…') : __('Updating…') }}
                                </span>
                            @endif
                        </div>

                        {{-- Auto-update Toggle --}}
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <span class="text-xs text-slate-400">{{ __('Auto-update') }}</span>
                            <button
                                type="button"
                                role="switch"
                                aria-checked="{{ $plugin['autoUpdate'] ? 'true' : 'false' }}"
                                wire:click="toggleAutoUpdate('{{ $slug }}')"
                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 {{ $plugin['autoUpdate'] ? 'bg-indigo-600' : 'bg-slate-700' }}"
                            >
                                <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform {{ $plugin['autoUpdate'] ? 'translate-x-4' : 'translate-x-1' }}"></span>
                            </button>
                        </label>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

            </div>{{-- /content column --}}
        </div>{{-- /grid --}}
    </div>{{-- /max-w container --}}
</div>
