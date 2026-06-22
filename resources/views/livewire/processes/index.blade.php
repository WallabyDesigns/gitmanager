<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        {{-- Header --}}
        <div class="flex items-center justify-between gap-4">
            @if ($totalActive > 0)
                <span class="shrink-0 text-xs px-2.5 py-1 rounded-full bg-cyan-500/10 text-cyan-300 border border-cyan-500/20">
                    {{ $totalActive }} {{ __('active') }}
                </span>
            @endif
        </div>

        {{-- Deployments & Tasks --}}
        <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-slate-100">{{ __('Deployments & Tasks') }}</h2>
                <p class="text-sm text-slate-400">{{ __('Active deployments, rollbacks, health checks, and audits.') }}</p>
            </div>

            @if ($runningDeployments->isEmpty())
                <p class="text-sm text-slate-500">{{ __('No deployments currently running.') }}</p>
            @else
                <div class="space-y-2">
                    @foreach ($runningDeployments as $dep)
                        @php
                            $depLabel = $actionLabels[$dep->action] ?? str_replace('_', ' ', ucfirst($dep->action));
                            $depDuration = $dep->started_at ? $dep->started_at->diffForHumans(null, true, false, 2) : null;
                            $depStale = $dep->started_at && $dep->started_at->diffInMinutes(now()) > 30;
                        @endphp
                        <div class="flex items-center justify-between gap-3 rounded-md border {{ $depStale ? 'border-amber-500/30' : 'border-slate-700' }} bg-slate-950/50 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $depStale ? 'bg-amber-400' : 'bg-emerald-400 animate-pulse' }}"></span>
                                    <span class="text-sm font-medium text-slate-100 truncate">{{ $dep->project->name ?? "Project #{$dep->project_id}" }}</span>
                                </div>
                                <div class="flex items-center gap-2 mt-0.5 pl-4">
                                    <span class="text-xs text-slate-400">{{ $depLabel }}</span>
                                    @if ($depDuration)
                                        <span class="text-xs {{ $depStale ? 'text-amber-400' : 'text-slate-500' }}">· {{ $depDuration }}</span>
                                    @endif
                                    @if ($dep->pid)
                                        <span class="text-xs text-slate-600 font-mono">PID {{ $dep->pid }}</span>
                                    @endif
                                </div>
                                @if ($depStale)
                                    <p class="text-xs text-amber-500 mt-1 pl-4">{{ __('Running longer than expected — may be stuck.') }}</p>
                                @endif
                            </div>
                            <button
                                type="button"
                                wire:click="failDeployment({{ $dep->id }})"
                                wire:confirm="{{ $dep->pid ? __('Send SIGTERM to the process and mark as failed?') : __('Mark as failed? No PID stored — the background process may still be running on the server.') }}"
                                class="shrink-0 px-2.5 py-1 text-xs rounded border border-rose-500/40 text-rose-300 hover:text-white hover:border-rose-400 transition-colors inline-flex items-center gap-1"
                            >
                                <x-loading-spinner target="failDeployment({{ $dep->id }})" />
                                {{ $dep->pid ? __('Kill') : __('Mark Failed') }}
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Node Services --}}
        <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-slate-100">{{ __('Node Services') }}</h2>
                <p class="text-sm text-slate-400">{{ __('Node.js processes managed by this application. Configure per-project in the project\'s Node tab.') }}</p>
            </div>

            @if ($activeNodeProcesses->isEmpty())
                <p class="text-sm text-slate-500">{{ __('No Node services running.') }}</p>
            @else
                <div class="space-y-2">
                    @foreach ($activeNodeProcesses as $np)
                        @php
                            $npDuration = $np->last_started_at ? $np->last_started_at->diffForHumans(null, true, false, 2) : null;
                        @endphp
                        <div class="flex items-center justify-between gap-3 rounded-md border border-slate-700 bg-slate-950/50 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-slate-100 truncate">{{ $np->project->name ?? "Project #{$np->project_id}" }}</span>
                                    @if ($np->port)
                                        <span class="text-xs text-slate-500 font-mono shrink-0">:{{ $np->port }}</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-xs font-mono text-slate-400 truncate">{{ $np->start_command }}</span>
                                    @if ($npDuration)
                                        <span class="text-xs text-slate-500 shrink-0">· {{ $npDuration }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-xs px-2 py-0.5 rounded-full
                                    {{ $np->status === 'running' ? 'bg-emerald-500/10 text-emerald-300' : '' }}
                                    {{ $np->status === 'starting' ? 'bg-indigo-500/10 text-indigo-300' : '' }}
                                    {{ $np->status === 'crashed' ? 'bg-rose-500/10 text-rose-300' : '' }}
                                ">{{ $np->status }}</span>
                                @if ($np->crash_count > 0)
                                    <span class="text-xs text-amber-400">{{ $np->crash_count }}×</span>
                                @endif
                                @if ($np->status !== 'crashed')
                                    <button
                                        type="button"
                                        wire:click="stopNodeProcess({{ $np->id }})"
                                        wire:confirm="{{ __('Stop this Node process?') }}"
                                        class="px-2.5 py-1 text-xs rounded border border-rose-500/40 text-rose-300 hover:text-white hover:border-rose-400 transition-colors inline-flex items-center gap-1"
                                    >
                                        <x-loading-spinner target="stopNodeProcess({{ $np->id }})" />
                                        {{ __('Stop') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
</div>
