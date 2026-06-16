<div class="py-10" wire:init="loadData">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs', ['systemTab' => $settingsSection])

            <div class="space-y-6">
                @if ($settingsSection === 'scheduler')
                    <div id="scheduler-settings" class="grid gap-6 lg:grid-cols-2 scroll-mt-24">
                        <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-3">
                            <div class="flex items-center gap-2">
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $schedulerHealthy ? 'bg-emerald-500/10 text-emerald-300' : 'bg-rose-100 bg-rose-500/10 text-rose-300' }}">
                                    {{ $schedulerHealthy ? __('Healthy') : __('Not detected') }}
                                </span>
                                <h3 class="text-sm font-semibold text-slate-100">{{ __('Scheduler Status') }}</h3>
                            </div>
                            <div class="text-sm text-slate-300 space-y-1">
                                <div>
                                    {{ __('Last heartbeat:') }}
                                    <span class="text-slate-100">
                                        {{ \App\Support\DateFormatter::forUser($lastHeartbeat, 'M j, Y g:i a', 'Never') }}
                                    </span>
                                </div>
                                <div>
                                    {{ __('Last source:') }}
                                    <span class="text-slate-100">
                                        {{ $lastSource ?? __('Unknown') }}
                                    </span>
                                </div>
                                <div>
                                    {{ __('Last manual run:') }}
                                    <span class="text-slate-100">
                                        {{ \App\Support\DateFormatter::forUser($lastManualRun, 'M j, Y g:i a', 'Never') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-100">{{ __('Queue Health') }}</h3>
                                <p class="text-sm text-slate-300">
                                    {{ __('Queued tasks:') }} <span class="text-slate-100">{{ $queueCount }}</span>
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="refreshSchedulerStatus" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center">
                                    <x-loading-spinner target="refreshSchedulerStatus" />
                                    {{ __('Refresh Status') }}
                                </button>
                                <button type="button" wire:click="runScheduler" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center">
                                    <x-loading-spinner target="runScheduler" />
                                    {{ __('Run Scheduler Now') }}
                                </button>
                                <button type="button" wire:click="processQueue" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center">
                                    <x-loading-spinner target="processQueue" />
                                    {{ __('Process Queue Now') }}
                                </button>
                            </div>
                            @if (! $queueEnabled)
                                <div class="text-xs text-amber-200">
                                    {{ __('Task queue is disabled in configuration.') }}
                                </div>
                            @endif
                        </div>
                    </div>

                    @if (! empty($schedulerLocks))
                        <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-amber-500/30 p-6 space-y-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-amber-300">{{ __('Active Schedule Locks') }}</h3>
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        {{ __('These withoutOverlapping locks are currently held. If a task stopped unexpectedly, its lock may be blocking the next run.') }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="clearAllScheduleLocks"
                                    class="shrink-0 px-3 py-1.5 text-xs rounded-md border border-amber-500/40 text-amber-200 hover:text-white hover:border-amber-400 inline-flex items-center gap-1.5"
                                >
                                    <x-loading-spinner target="clearAllScheduleLocks" />
                                    {{ __('Clear All') }}
                                </button>
                            </div>
                            <div class="space-y-2">
                                @foreach ($schedulerLocks as $lock)
                                    <div class="flex items-center justify-between gap-3 rounded-md border border-slate-700 bg-slate-950/50 px-4 py-2.5">
                                        <div class="min-w-0">
                                            <div class="text-sm font-mono text-slate-100 truncate">{{ $lock['label'] }}</div>
                                            <div class="text-xs text-slate-400 mt-0.5">
                                                {{ __('Expires in') }}
                                                @if ($lock['expires_in_seconds'] < 60)
                                                    {{ $lock['expires_in_seconds'] }}{{ __('s') }}
                                                @elseif ($lock['expires_in_seconds'] < 3600)
                                                    {{ (int) round($lock['expires_in_seconds'] / 60) }}{{ __('m') }}
                                                @else
                                                    {{ (int) round($lock['expires_in_seconds'] / 3600, 1) }}{{ __('h') }}
                                                @endif
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="clearScheduleLock('{{ $lock['key'] }}')"
                                            title="{{ __('Clear this lock') }}"
                                            class="shrink-0 p-1.5 rounded text-slate-400 hover:text-white hover:bg-slate-700 transition-colors"
                                        >
                                            <x-loading-spinner target="clearScheduleLock('{{ $lock['key'] }}')" />
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Task Frequency') }}</h3>
                            <p class="text-sm text-slate-400">
                                {{ __('Keep the cron running every minute. These controls decide how often each recurring task actually executes.') }}
                            </p>
                        </div>

                        <div class="space-y-4">
                            @foreach ($schedulerTaskDefinitions as $task => $definition)
                                @php
                                    $taskStatus = $schedulerTaskStatuses[$task] ?? ['enabled' => true, 'label' => 'Enabled'];
                                @endphp
                                <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <div class="text-sm font-semibold text-slate-100">{{ $definition['label'] }}</div>
                                                <span class="px-2 py-1 rounded-full text-[11px] font-semibold uppercase tracking-wide {{ ($taskStatus['enabled'] ?? false) ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-500/20 text-slate-200' }}">
                                                    {{ $taskStatus['label'] ?? (($taskStatus['enabled'] ?? false) ? 'Enabled' : 'Disabled') }}
                                                </span>
                                            </div>
                                            <div class="mt-1 text-xs text-slate-400">{{ $definition['description'] }}</div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="number"
                                                min="1"
                                                max="{{ ($schedulerTaskIntervals[$task]['unit'] ?? $definition['default_unit']) === 'hours' ? 24 : 59 }}"
                                                wire:model.defer="schedulerTaskIntervals.{{ $task }}.value"
                                                class="w-24 rounded-md border px-3 py-2 text-sm border-slate-700 bg-slate-950 text-slate-100"
                                            />
                                            <select
                                                wire:model.defer="schedulerTaskIntervals.{{ $task }}.unit"
                                                class="w-24 rounded-md border px-3 py-2 text-sm border-slate-700 bg-slate-950 text-slate-100"
                                            >
                                                @foreach ($schedulerTaskUnitOptions as $unit => $label)
                                                    <option value="{{ $unit }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <x-input-error :messages="$errors->get('schedulerTaskIntervals.'.$task.'.value')" class="mt-3" />
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Stored Log Cleanup') }}</h3>
                            <span class="px-2 py-1 rounded-full text-[11px] font-semibold uppercase tracking-wide {{ $logCleanupEnabled ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-500/20 text-slate-200' }}">
                                {{ $logCleanupEnabled ? __('Enabled') : __('Disabled') }}
                            </span>
                        </div>
                        <p class="text-sm text-slate-400">
                            {{ __('Clears old deployment and system update console logs from the database while keeping the history rows themselves.') }}
                        </p>

                        <label class="flex items-start gap-3">
                            <input type="checkbox" wire:model="logCleanupEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                            <span class="text-sm text-slate-300">
                                {{ __('Enable automatic stored log cleanup') }}
                                <span class="block text-xs text-slate-500">{{ __('Runs once per day when enabled.') }}</span>
                            </span>
                        </label>

                        <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-slate-100">{{ __('Retention Window') }}</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ __('Remove stored logs older than this many days.') }}</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input
                                        type="number"
                                        min="1"
                                        max="3650"
                                        wire:model.defer="logRetentionDays"
                                        class="w-28 rounded-md border px-3 py-2 text-sm border-slate-700 bg-slate-950 text-slate-100"
                                    />
                                    <span class="rounded-md border px-3 py-2 text-sm border-slate-700 bg-slate-950 text-slate-300">{{ __('Days') }}</span>
                                </div>
                            </div>
                            <x-input-error :messages="$errors->get('logRetentionDays')" class="mt-3" />
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="runLogCleanup" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center">
                                <x-loading-spinner target="runLogCleanup" />
                                {{ __('Run Cleanup Now') }}
                            </button>
                            <button
                                type="button"
                                wire:click="clearAllStoredLogs"
                                onclick="return confirm('{{ __('This will clear all stored deployment, self-update, and scheduler error logs. Continue?') }}')"
                                class="px-3 py-2 text-xs rounded-md border border-rose-500/40 text-rose-200 hover:text-white inline-flex items-center"
                            >
                                <x-loading-spinner target="clearAllStoredLogs" />
                                {{ __('Clear All Stored Logs') }}
                            </button>
                        </div>
                        <div class="text-xs text-slate-500">
                            {{ __('Manual cleanup keeps deployment and update status history, but removes large console output and scheduler error logs. The Clear All action also runs SQLite VACUUM when supported so disk space can be reclaimed.') }}
                        </div>
                    </div>

                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Cron Setup') }}</h3>
                            <p class="text-sm text-slate-400">
                                {{ __('Add the cron entry below to run the Laravel scheduler every minute.') }}
                            </p>
                        </div>
                        <div class="rounded-md border border-slate-700 bg-slate-950/40 p-3 text-xs font-mono text-slate-200">
                            {{ $cronCommand }}
                        </div>
                        @if (! ($schedulerRuntime['ok'] ?? true))
                            <div class="rounded-md border border-rose-500/40 bg-rose-500/10 p-4 space-y-2">
                                <div class="text-sm font-semibold text-rose-200">{{ __('Scheduler PHP Runtime Issue') }}</div>
                                <p class="text-xs text-rose-200">
                                    {{ $schedulerRuntime['message'] ?? __('The configured PHP binary failed the scheduler preflight.') }}
                                </p>
                                <div class="text-xs text-rose-200">
                                    {{ __('Configured PHP:') }} <code class="font-mono">{{ $schedulerRuntime['php_binary'] ?? 'php' }}</code>
                                    @if (! empty($schedulerRuntime['resolved_binary']))
                                        <span class="mx-1">&middot;</span>
                                        {{ __('Resolved:') }} <code class="font-mono">{{ $schedulerRuntime['resolved_binary'] }}</code>
                                    @endif
                                    @if (! empty($schedulerRuntime['version']))
                                        <span class="mx-1">&middot;</span>
                                        PHP {{ $schedulerRuntime['version'] }}
                                    @endif
                                </div>
                                <p class="text-xs text-rose-300">
                                    Install/enable the missing extension for CLI PHP, or set <code class="font-mono">GWM_PHP_BINARY</code> to a PHP binary that includes it.
                                </p>
                            </div>
                        @else
                            <div class="rounded-md border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs text-emerald-200">
                                Scheduler PHP preflight passed for <code class="font-mono">{{ $schedulerRuntime['php_binary'] ?? 'php' }}</code>.
                            </div>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="installCron" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center">
                                <x-loading-spinner target="installCron" />
                                Install Cron (Best Effort)
                            </button>
                        </div>
                        <p class="text-xs text-slate-400">
                            If automatic install fails, add the cron line manually or configure a Task Scheduler entry on Windows.
                        </p>
                    </div>

                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Scheduler Error Log') }}</h3>
                            <p class="text-sm text-slate-300">
                                Entries are recorded only when the scheduler reports an issue. Repeated errors increase the count.
                            </p>
                        </div>

                        @if (empty($schedulerLog))
                            <div class="text-sm text-slate-400">
                                No scheduler errors recorded yet.
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach ($schedulerLog as $entry)
                                    <div class="rounded-md border border-slate-800 bg-slate-950/40 p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="text-sm font-semibold text-slate-100">
                                                {{ $entry['message'] ?? __('Scheduler error') }}
                                            </div>
                                            <div class="text-xs uppercase tracking-wide text-slate-400">
                                                Error Count: {{ $entry['count'] ?? 1 }}
                                            </div>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-400">
                                            First seen: {{ $entry['first_seen'] ?? 'Unknown' }} · Last seen: {{ $entry['last_seen'] ?? 'Unknown' }}
                                        </div>
                                        @if (! empty($entry['output']))
                                            <pre
                                                class="mt-3 max-h-48 overflow-auto rounded-md border border-slate-800 bg-slate-900/60 p-3 text-xs text-slate-200 whitespace-pre-wrap"
                                                x-data
                                                x-init="
                                                    const el = $el;
                                                    const scrollToBottom = () => { el.scrollTop = el.scrollHeight; };
                                                    $nextTick(scrollToBottom);
                                                    const observer = new MutationObserver(scrollToBottom);
                                                    observer.observe(el, { childList: true, characterData: true, subtree: true });
                                                    if (typeof $cleanup === 'function') {
                                                        $cleanup(() => observer.disconnect());
                                                    }
                                                "
                                            >{{ $entry['output'] }}</pre>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-3" x-data="{ saved: false, timer: null }" x-on:settings-saved.window="
                        saved = true;
                        clearTimeout(timer);
                        timer = setTimeout(() => saved = false, 2000);
                    ">
                        <button type="button" wire:click="save" wire:loading.attr="disabled" disabled wire:dirty.remove.attr="disabled" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center">
                            <x-loading-spinner target="save" />
                            {{ __('Save Settings') }}
                        </button>
                        <span wire:dirty class="text-xs text-amber-400">{{ __('Settings are unsaved.') }}</span>
                        <span x-show="saved" x-transition.opacity.duration.200ms class="text-xs text-emerald-400">{{ __('Settings saved.') }}</span>
                    </div>
                @endif

                @if ($settingsSection === 'node')
                    {{-- Node.js Runtime Status --}}
                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ ($nodeStatus['found'] ?? false) ? 'bg-emerald-500/10 text-emerald-300' : 'bg-rose-500/10 text-rose-300' }}">
                                        {{ ($nodeStatus['found'] ?? false) ? __('Detected') : __('Not found') }}
                                    </span>
                                    <h3 class="text-sm font-semibold text-slate-100">{{ __('Node.js Runtime') }}</h3>
                                </div>
                                @if ($nodeStatus['found'] ?? false)
                                    <div class="mt-2 text-sm text-slate-300 space-y-1">
                                        <div>{{ __('Version:') }} <span class="text-slate-100 font-mono">{{ $nodeStatus['version'] ?? '—' }}</span></div>
                                        <div>{{ __('Source:') }} <span class="text-slate-100">{{ $nodeStatus['source'] === 'bundled' ? __('Bundled (app-managed)') : __('System PATH') }}</span></div>
                                        <div class="text-xs text-slate-500 font-mono truncate">{{ $nodeStatus['binary'] ?? '' }}</div>
                                    </div>
                                @else
                                    <p class="mt-1 text-sm text-slate-400">{{ __('Node.js was not found. Install it below to enable Node process management.') }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 pt-1">
                            @if (! ($nodeStatus['found'] ?? false))
                                <button type="button" wire:click="installNode" class="px-3 py-2 text-xs rounded-md border border-emerald-500/40 text-emerald-200 hover:text-white inline-flex items-center gap-1.5">
                                    <x-loading-spinner target="installNode" />
                                    {{ __('Install Node.js LTS (Bundled)') }}
                                </button>
                            @else
                                <button type="button" wire:click="installNode" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center gap-1.5">
                                    <x-loading-spinner target="installNode" />
                                    {{ __('Reinstall / Upgrade') }}
                                </button>
                                @if (($nodeStatus['source'] ?? '') === 'bundled')
                                    <button
                                        type="button"
                                        wire:click="uninstallNode"
                                        onclick="return confirm('{{ __('Remove the bundled Node.js runtime? Running processes will stop.') }}')"
                                        class="px-3 py-2 text-xs rounded-md border border-rose-500/40 text-rose-200 hover:text-white inline-flex items-center gap-1.5"
                                    >
                                        <x-loading-spinner target="uninstallNode" />
                                        {{ __('Remove Bundled Runtime') }}
                                    </button>
                                @endif
                            @endif
                        </div>

                        <p class="text-xs text-slate-500">
                            {{ __('The bundled runtime is downloaded from nodejs.org and stored in your app\'s storage directory — no root access required. If Node.js is already on your system PATH it will be used automatically.') }}
                        </p>
                    </div>

                    {{-- Active Node Processes Summary --}}
                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Active Processes') }}</h3>
                            <p class="text-sm text-slate-400">{{ __('Node processes are configured per project in the project\'s Node tab.') }}</p>
                        </div>
                        @php
                            $nodeProcesses = \App\Models\NodeProcess::query()
                                ->with('project:id,name')
                                ->whereIn('status', [\App\Models\NodeProcess::STATUS_RUNNING, \App\Models\NodeProcess::STATUS_STARTING, \App\Models\NodeProcess::STATUS_CRASHED])
                                ->get();
                        @endphp
                        @if ($nodeProcesses->isEmpty())
                            <p class="text-sm text-slate-400">{{ __('No active Node processes.') }}</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($nodeProcesses as $np)
                                    <div class="flex items-center justify-between gap-3 rounded-md border border-slate-700 bg-slate-950/50 px-4 py-2.5">
                                        <div class="min-w-0">
                                            <div class="text-sm text-slate-100 truncate">{{ $np->project->name ?? "Project #{$np->project_id}" }}</div>
                                            <div class="text-xs text-slate-400 font-mono">{{ $np->start_command }}</div>
                                        </div>
                                        <div class="flex items-center gap-3 shrink-0">
                                            @if ($np->port)
                                                <span class="text-xs text-slate-400">:{{ $np->port }}</span>
                                            @endif
                                            <span class="text-xs px-2 py-0.5 rounded-full
                                                {{ $np->status === 'running' ? 'bg-emerald-500/10 text-emerald-300' : '' }}
                                                {{ $np->status === 'starting' ? 'bg-indigo-500/10 text-indigo-300' : '' }}
                                                {{ $np->status === 'crashed' ? 'bg-rose-500/10 text-rose-300' : '' }}
                                            ">
                                                {{ $np->status }}
                                            </span>
                                            @if ($np->crash_count > 0)
                                                <span class="text-xs text-amber-400">{{ $np->crash_count }} crash{{ $np->crash_count !== 1 ? 'es' : '' }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if ($settingsSection === 'diagnostics')
                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-6">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-100">{{ __('Runtime & Build Tools') }}</h3>
                                <p class="text-sm text-slate-400">{{ __('Detected runtimes and build tools available to this application.') }}</p>
                            </div>
                            <button type="button" wire:click="$refresh" class="shrink-0 px-3 py-1.5 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center gap-1.5">
                                <x-loading-spinner target="$refresh" />
                                {{ __('Re-check') }}
                            </button>
                        </div>

                        @if ($runtimeDiagnostics)
                            <div class="space-y-2">
                                @foreach ($runtimeDiagnostics as $key => $tool)
                                    <div class="rounded-md border border-slate-700 bg-slate-950/50 px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <span class="text-xs uppercase tracking-wide px-2 py-0.5 rounded-full {{ $tool['found'] ? 'bg-emerald-500/10 text-emerald-300' : 'bg-rose-500/10 text-rose-300' }}">
                                                {{ $tool['found'] ? __('Detected') : __('Missing') }}
                                            </span>
                                            <span class="text-sm font-medium text-slate-100">{{ $tool['label'] }}</span>
                                            @if ($tool['found'])
                                                <span class="text-xs font-mono text-slate-400">v{{ $tool['version'] }}</span>
                                            @endif
                                        </div>
                                        @if (! $tool['found'])
                                            <div class="mt-3 space-y-3">
                                                @if ($tool['installAction'] === 'installNode')
                                                    <div class="flex flex-wrap items-center gap-3">
                                                        <button type="button" wire:click="installNode" wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed" class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium rounded-md bg-indigo-600 hover:bg-indigo-500 text-white disabled:opacity-60 disabled:cursor-not-allowed">
                                                            <x-loading-spinner target="installNode" />
                                                            {{ __('Install Node.js + npm') }}
                                                        </button>
                                                        <span class="text-xs text-slate-500">{{ __('Downloads and installs the Node.js LTS runtime into GWM\'s storage directory.') }}</span>
                                                    </div>
                                                @endif
                                                @if ($tool['guidance'])
                                                    <div x-data="{ copied: false }">
                                                        <p class="text-xs text-slate-500 mb-1">
                                                            {{ $tool['installAction'] ? __('Or install manually:') : __('Install guidance:') }}
                                                        </p>
                                                        <div class="flex items-stretch gap-2">
                                                            <code class="flex-1 text-xs font-mono text-amber-300 bg-slate-900 border border-slate-700 rounded px-3 py-2 whitespace-pre-wrap">{{ $tool['guidance'] }}</code>
                                                            <button type="button"
                                                                x-on:click="navigator.clipboard?.writeText('{{ addslashes($tool['guidance']) }}').then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                                                                :title="copied ? '{{ __('Copied!') }}' : '{{ __('Copy command') }}'"
                                                                class="shrink-0 flex items-center px-2.5 rounded border border-slate-700 text-slate-400 hover:text-slate-200 hover:border-slate-500 transition-colors">
                                                                <svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                                </svg>
                                                                <svg x-show="copied" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if ($settingsSection === 'application')
                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Update Preferences') }}</h3>
                            <p class="text-sm text-slate-400">{{ __('Control how Git Web Manager checks for and applies updates.') }}</p>
                        </div>

                        <div class="space-y-4">
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="checkUpdates" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-slate-300">
                                    {{ __('Check for updates') }}
                                    <span class="block text-xs text-slate-500">{{ __('Shows update availability in the System area and navigation.') }}</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="autoUpdate" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-slate-300">
                                    {{ __('Perform auto-updates') }}
                                    <span class="block text-xs text-slate-500">{{ __('Runs the nightly self-update schedule when enabled.') }}</span>
                                </span>
                            </label>
                            <div class="text-xs {{ $autoUpdate ? 'text-emerald-400' : 'text-slate-400 text-slate-500' }}">
                                {{ $autoUpdate ? 'Auto-updates are enabled.' : 'Auto-updates are disabled. Manual updates remain available in System Updates.' }}
                            </div>
                        </div>
                    </div>

                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('GitHub Security') }}</h3>
                            <p class="text-sm text-slate-400">{{ __('Control SSL verification for GitHub API calls.') }}</p>
                        </div>
                        <label class="flex items-start gap-3">
                            <input type="checkbox" wire:model="githubSslVerify" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                            <span class="text-sm text-slate-300">
                                {{ __('Verify GitHub SSL certificates') }}
                                <span class="block text-xs text-slate-500">{{ __('Disable only if your host is missing CA certificates.') }}</span>
                            </span>
                        </label>
                        @if (! $githubSslVerify)
                            <div class="text-xs text-rose-400">{{ __('Warning: SSL verification is disabled. GitHub API calls are less secure.') }}</div>
                        @endif
                    </div>

                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Login Security') }}</h3>
                            <p class="text-sm text-slate-400">{{ __('Configure Cloudflare Turnstile CAPTCHA to protect the login page from bots and brute-force attacks.') }}</p>
                        </div>

                        <div class="space-y-4">
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="captchaEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-slate-300">
                                    {{ __('Enable Turnstile CAPTCHA on login') }}
                                    <span class="block text-xs text-slate-500">{{ __('Requires both a Site Key and Secret Key to be saved below.') }}</span>
                                </span>
                            </label>

                            <div>
                                <label class="block text-sm text-slate-300 mb-1" for="captcha-site-key-app">{{ __('Site Key') }}</label>
                                <input
                                    type="text"
                                    id="captcha-site-key-app"
                                    wire:model="captchaSiteKey"
                                    class="block w-full rounded-md border border-slate-700 bg-slate-950 text-slate-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    placeholder="0x4AAAAAAA..."
                                    autocomplete="off"
                                />
                            </div>

                            <div>
                                <label class="block text-sm text-slate-300 mb-1" for="captcha-secret-key-app">{{ __('Secret Key') }}</label>
                                <input
                                    type="password"
                                    id="captcha-secret-key-app"
                                    wire:model="captchaSecretKey"
                                    class="block w-full rounded-md border border-slate-700 bg-slate-950 text-slate-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    placeholder="{{ $captchaEnabled && $captchaSiteKey !== '' ? '••••••••' : '' }}"
                                    autocomplete="new-password"
                                />
                                @if ($captchaEnabled && $captchaSiteKey !== '')
                                    <p class="mt-1 text-xs text-slate-500">{{ __('Leave blank to keep the existing secret key.') }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="pt-2 flex items-center gap-4">
                            <button type="button" wire:click="saveSecurity" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 inline-flex items-center">
                                <x-loading-spinner target="saveSecurity" />
                                {{ __('Save Security Settings') }}
                            </button>
                        </div>

                        <div class="border-t border-slate-800 pt-4">
                            <p class="text-xs text-slate-500">
                                {{ __('Get your Turnstile Site Key and Secret Key from the') }}
                                <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer" class="text-indigo-400 hover:text-indigo-300 underline">{{ __('Cloudflare Dashboard') }}</a>.
                                {{ __('Choose "Managed" challenge type for best results.') }}
                            </p>
                        </div>
                    </div>

                    @if ($isLocalInstall)
                        <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-100">{{ __('SSL / Connection Repair') }}</h3>
                                <p class="text-sm text-slate-400">
                                    {{ __('Fixes cURL error 60 (missing CA certificate bundle) for local installations. Enables a TLS bypass so outbound license verification requests succeed when your host lacks a valid CA trust store.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                <button
                                    type="button"
                                    wire:click="runLocalLicenseRepair"
                                    class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center"
                                >
                                    <x-loading-spinner target="runLocalLicenseRepair" />
                                    {{ __('Run Best-Effort SSL Fix') }}
                                </button>
                                @if ($localLicenseTlsBypassEnabled)
                                    <span class="text-xs text-emerald-400">{{ __('Local TLS bypass is active.') }}</span>
                                @else
                                    <span class="text-xs text-slate-500">{{ __('Local TLS bypass is not yet enabled.') }}</span>
                                @endif
                            </div>
                            <p class="text-xs text-slate-500">
                                {{ __('Only available on local installs. This setting does not affect production SSL behaviour.') }}
                            </p>
                        </div>
                    @endif

                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Timezone') }}</h3>
                            <p class="text-sm text-slate-400">{{ __('Sets the default timezone used throughout the app.') }}</p>
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-wide text-slate-500">{{ __('Timezone') }}</label>
                            <select wire:model.live="timezone" wire:key="system-timezone-{{ $timezone }}" class="mt-2 w-full rounded-md border p-2 text-sm border-slate-700 bg-slate-950 text-slate-100">
                                @foreach ($timezones as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current value:') }} {{ $timezone ?: 'UTC' }}</p>
                        </div>
                    </div>
                @endif

                @if ($settingsSection === 'audits')
                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Audit Checks') }}</h3>
                            <p class="text-sm text-slate-400">{{ __('Run scheduled project and container audits and track issues in System Security.') }}</p>
                        </div>
                        @if ($isEnterprise)
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="auditEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-slate-300">
                                    {{ __('Enable automatic audits') }}
                                    <span class="block text-xs text-slate-500">{{ __('Runs npm/composer project audits and managed container runtime checks on the scheduler.') }}</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="auditEmailEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-slate-300">
                                    {{ __('Email audit summaries') }}
                                    <span class="block text-xs text-slate-500">{{ __('Sends a consolidated report when issues are found or resolved.') }}</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="auditAutoCommit" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-slate-300">
                                    {{ __('Auto-commit resolved audit fixes') }}
                                    <span class="block text-xs text-slate-500">{{ __('Pushes dependency lockfile changes when audits resolve all vulnerabilities.') }}</span>
                                </span>
                            </label>
                            @if (! $mailConfigured)
                                <div class="text-xs text-rose-400">{{ __('Email is not configured. Set SMTP details in System → Email Settings to enable audit emails.') }}</div>
                            @endif
                            @if (! $auditEnabled)
                                <div class="text-xs text-slate-500">{{ __('Scheduled audits are disabled.') }}</div>
                            @endif
                        @else
                            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-4">
                                <div class="text-sm font-semibold text-amber-200">{{ __('Enterprise Feature') }}</div>
                                <p class="mt-1 text-xs text-amber-200">
                                    {{ __('Automatic project and container audits are available on Enterprise. Upgrade to unlock hourly audit automation and alerting.') }}
                                </p>
                                <button
                                    type="button"
                                    onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Automatic Project & Container Audits' } }));"
                                    class="mt-3 inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-xs font-semibold border-amber-500/60 text-amber-300 hover:text-amber-200"
                                >
                                    Get Enterprise
                                </button>
                            </div>
                        @endif
                    </div>

                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Health Alerts') }}</h3>
                            <p class="text-sm text-slate-400">{{ __('Email notifications when projects go offline during automatic checks.') }}</p>
                        </div>
                        <label class="flex items-start gap-3">
                            <input type="checkbox" wire:model="healthEmailEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                            <span class="text-sm text-slate-300">
                                {{ __('Send email alerts when health checks fail') }}
                                <span class="block text-xs text-slate-500">{{ __('Only automatic checks trigger emails. Manual checks never send alerts.') }}</span>
                            </span>
                        </label>
                        @if (! $mailConfigured)
                            <div class="text-xs text-rose-400">{{ __('Email is not configured. Set SMTP details in System → Email Settings to enable health alerts.') }}</div>
                        @endif
                        @if (! $healthEmailEnabled)
                            <div class="text-xs text-slate-500">{{ __('Health alert emails are disabled.') }}</div>
                        @endif
                    </div>
                @endif

                @if ($settingsSection === 'licensing')
                    @php
                        $licenseStatusLower = strtolower((string) ($licenseState['status'] ?? 'missing'));
                        $showLocalLicenseWarning = $isLocalInstall && $licenseStatusLower !== 'valid';
                        $licenseMessage = trim((string) ($licenseState['message'] ?? ''));
                        $licenseMessageLower = strtolower($licenseMessage);

                        $isTlsError = str_contains($licenseMessageLower, 'ssl') || str_contains($licenseMessageLower, 'curl error 60');
                        if ($isTlsError) {
                            $localWarningDetail = __('TLS trust is not configured for this host. Run SSL / Connection Repair on the App & Security tab, then re-verify.');
                        } else {
                            $localWarningDetail = __('License verification must pass before this installation can unlock Enterprise features.');
                        }
                    @endphp

                    @if ($showLocalLicenseWarning)
                        <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 space-y-3">
                            <div class="space-y-1">
                                <div class="text-sm font-semibold text-amber-200">{{ __('Local Installation Notice') }}</div>
                                <p class="text-xs text-amber-200">{{ $localWarningDetail }}</p>
                            </div>
                            @if ($localLicenseTlsBypassEnabled)
                                <p class="text-xs text-emerald-400">
                                    ✓ {{ __('Local TLS bypass is active — outbound HTTPS connections are allowed without a CA bundle.') }}
                                </p>
                            @endif
                            @if ($licenseMessage !== '')
                                <p class="text-xs text-amber-300">{{ $licenseMessage }}</p>
                            @endif
                            @if ($isTlsError)
                                <p class="text-xs text-amber-300">
                                    {{ __('Use :link to fix this automatically.', ['link' => '<a href="'.route('system.application').'" class="underline">App &amp; Security → SSL / Connection Repair</a>']) }}
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                        @php
                            $licenseStatus = (string) ($licenseState['status'] ?? 'missing');
                            $licenseBadgeClass = match ($licenseStatus) {
                                'valid' => 'gwm-status-success',
                                'invalid' => 'gwm-status-danger',
                                'unverified' => 'gwm-status-warning',
                                default => 'gwm-status-muted',
                            };
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-100">{{ __('Enterprise License') }}</h3>
                                <p class="text-sm text-slate-400">{{ __('License administration handled by Git Web Manager, this panel only verifies the license. If you have your license key, enter it below.') }}</p>
                            </div>
                            @if($licenseStatus !== 'missing')
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $licenseBadgeClass }}">
                                    {{ $licenseStatus }}
                                </span>
                            @endif
                        </div>

                        <div class="space-y-2 text-xs text-slate-400">
                            @if(!empty($licenseState['configured']))
                                <div>{{ __('Configured:') }} {{ ! empty($licenseState['configured']) ? __('Yes') : __('No') }}</div>
                            @endif
                            <div>{{ __('Licensed Edition:') }} {{ ucfirst((string) ($licenseState['edition'] ?? 'community')) }}</div>
                            <div>{{ __('Package Version:') }} {{ $systemPackageVersion }}</div>
                            @if(isset($licenseState['installation_uuid']))
                                <div>{{ __('Installation UUID:') }} {{ $licenseState['installation_uuid'] ?? __('Unknown') }}</div>
                            @endif
                            @if(isset($licenseState['message']) && $licenseState['message'] != "No license key configured.")
                                <div>{{ __('Message:') }} {{ $licenseState['message'] ?? __('No license state available.') }}</div>
                            @endif
                            @if(isset($licenseState['bound_ip']))
                                <div>{{ __('Bound IP:') }} {{ $licenseState['bound_ip'] ?? __('Unknown') }}</div>
                            @endif
                            @if(isset($licenseState['detected_ip']))
                                <div>{{ __('Detected IP:') }} {{ $licenseState['detected_ip'] ?? __('Unknown') }}</div>
                            @endif
                            @if(isset($licenseState['verified_at']))
                                <div>{{ __('Verified At:') }} {{ \App\Support\DateFormatter::forUser($licenseState['verified_at'] ?? null, 'M j, Y g:i a', __('Never')) }}</div>
                            @endif
                            @if(isset($licenseState['expires_at']))
                                <div>{{ __('Expires At:') }} {{ \App\Support\DateFormatter::forUser($licenseState['expires_at'] ?? null, 'M j, Y g:i a', __('Not provided')) }}</div>
                            @endif
                            @if(isset($licenseState['grace_ends_at']))
                                <div>{{ __('Grace Ends At:') }} {{ \App\Support\DateFormatter::forUser($licenseState['grace_ends_at'] ?? null, 'M j, Y g:i a', __('Not provided')) }}</div>
                            @endif
                        </div>

                        <div>
                            <label class="text-xs uppercase tracking-wide text-slate-500">{{ __('License Key') }}</label>
                            <input type="text" wire:model.lazy="enterpriseLicenseKey" class="mt-2 w-full rounded-md border p-2 text-xs border-slate-700 bg-slate-950 text-slate-100" placeholder="{{ __('Enter new license key to set or rotate') }}" />
                            <x-input-error :messages="$errors->get('enterpriseLicenseKey')" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-500">{{ __('Leave blank to keep the current saved key.') }}</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if (! $isEnterprise || $licenseStatusLower !== 'valid')
                                <button
                                    type="button"
                                    onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Edition' } }));"
                                    class="px-3 py-2 text-xs rounded-md border border-amber-500/60 text-amber-300 hover:text-amber-200 inline-flex items-center"
                                >
                                    Get Enterprise
                                </button>
                            @endif
                            <button type="button" wire:click="verifyLicense" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center">
                                <x-loading-spinner target="verifyLicense" />
                                Verify License Now
                            </button>
                            <button type="button" wire:click="clearLicense" onclick="return confirm('{{ __('Clear the saved license key and cached state?') }}') || event.stopImmediatePropagation()" class="px-3 py-2 text-xs rounded-md border border-rose-500/60 text-rose-300 hover:text-rose-100 inline-flex items-center">
                                <x-loading-spinner target="clearLicense" />
                                Clear License
                            </button>
                        </div>
                    </div>
                @endif

                @if ($settingsSection === 'environment')
                    <div class="space-y-6">
                        {{-- GWM Keys --}}
                        <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-100">{{ __('GWM Environment Variables') }}</h3>
                                    <p class="text-xs text-slate-400 mt-0.5">{!! __('All :keys keys from your :env file. Changes take effect immediately.', ['keys' => '<code class="font-mono">GWM_*</code>', 'env' => '.env</code>']) !!}</p>
                                </div>
                            </div>
                            <div class="divide-y divide-slate-800">
                                @forelse ($gwmKeys as $key => $meta)
                                    <div class="px-6 py-4 grid grid-cols-1 lg:grid-cols-[1fr,auto] gap-3 items-start">
                                        <div class="space-y-1 min-w-0">
                                            <div class="text-xs font-mono font-semibold text-indigo-400">{{ $key }}</div>
                                            @if ($meta['description'])
                                                <div class="text-xs text-slate-400">{{ $meta['description'] }}</div>
                                            @endif
                                            <input
                                            wire:model="gwmEdits.{{ $key }}"
                                            type="{{ str_contains(strtolower($key), 'password') || str_contains(strtolower($key), 'secret') ? 'password' : 'text' }}"
                                            placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                            class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm font-mono text-slate-100 placeholder:text-slate-600 focus:border-indigo-400 focus:outline-none"
                                        >
                                        @if ($meta['default'] !== '' && ($gwmEdits[$key] ?? '') === '')
                                            <p class="mt-1 text-xs text-slate-500">{{ __('Default:') }} <code class="font-mono">{{ $meta['default'] }}</code></p>
                                        @endif
                                        </div>
                                        <button
                                            wire:click="saveEnvKey('{{ $key }}')"
                                            class="shrink-0 mt-6 lg:mt-7 px-3 py-1.5 rounded-md border border-indigo-400/60 text-xs font-semibold text-indigo-300 hover:bg-indigo-500/10 transition inline-flex items-center gap-1"
                                        >
                                            <x-loading-spinner target="saveEnvKey('{{ $key }}')" />
                                            {{ __('Save') }}
                                        </button>
                                    </div>
                                @empty
                                    <div class="px-6 py-8 text-sm text-slate-400">{{ __('No GWM_* variables found in your .env file.') }}</div>
                                @endforelse
                            </div>
                        </div>

                        {{-- Env Backups --}}
                        <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-800">
                                <h3 class="text-sm font-semibold text-slate-100">{{ __('Environment Backups') }}</h3>
                                <p class="text-xs text-slate-400 mt-0.5">{{ __('Snapshots of your :env file. Restore any backup to roll back configuration changes. The current file is saved automatically before each restore.', ['env' => '<code class="font-mono">.env</code>']) }}</p>
                            </div>
                            <div class="px-6 py-4 flex items-center gap-3 border-b border-slate-800">
                                <input wire:model="envBackupLabel" type="text" placeholder="{{ __('Label (optional)') }}"
                                        class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none w-48">
                                <button wire:click="createEnvBackup" class="px-3 py-1.5 text-xs rounded-md border border-slate-700 text-slate-200 hover:border-indigo-300 hover:text-indigo-600 transition inline-flex items-center gap-1">
                                    <x-loading-spinner target="createEnvBackup" />
                                    {{ __('Create Backup') }}
                                </button>
                            </div>
                            @if (count($envBackups) > 0)
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-slate-800/50">
                                            <tr>
                                                @foreach ([__('Filename'), __('Created'), __('Size'), __('Actions')] as $h)
                                                    <th class="px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">{{ $h }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-800">
                                            @foreach ($envBackups as $backup)
                                                <tr class="hover:bg-slate-800/30">
                                                    <td class="px-4 py-3 font-mono text-xs text-slate-300">{{ $backup['filename'] }}</td>
                                                    <td class="px-4 py-3 text-xs text-slate-400 whitespace-nowrap">{{ $backup['created_at'] }}</td>
                                                    <td class="px-4 py-3 text-xs text-slate-400 whitespace-nowrap">{{ number_format($backup['size'] / 1024, 1) }} KB</td>
                                                    <td class="px-4 py-3">
                                                        <div class="flex items-center gap-2">
                                                            <button
                                                                wire:click="restoreEnvBackup('{{ $backup['filename'] }}')"
                                                                wire:confirm="{{ __('Restore this backup? Your current .env will be saved first.') }}"
                                                                class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-400 hover:border-indigo-300 hover:text-indigo-600 transition">
                                                                {{ __('Restore') }}
                                                            </button>
                                                            <button
                                                                wire:click="deleteEnvBackup('{{ $backup['filename'] }}')"
                                                                wire:confirm="{{ __('Delete this backup permanently?') }}"
                                                                class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-500 hover:border-rose-300 hover:text-rose-600 transition">
                                                                {{ __('Delete') }}
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <div class="px-6 py-8 text-sm text-slate-400">{{ __('No backups yet. Create one above.') }}</div>
                            @endif
                        </div>
                    </div>
                @endif

                @if (! in_array($settingsSection, ['environment', 'scheduler'], true))
                <div class="flex flex-wrap items-center gap-3" x-data="{ saved: false, timer: null }" x-on:settings-saved.window="
                    saved = true;
                    clearTimeout(timer);
                    timer = setTimeout(() => saved = false, 2000);
                ">
                    <button type="button" wire:click="save" wire:loading.attr="disabled" disabled wire:dirty.remove.attr="disabled" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center">
                        <x-loading-spinner target="save" />
                        Save Settings
                    </button>
<span wire:dirty class="text-xs text-amber-400">{{ __('Settings are unsaved.') }}</span>
                        <span x-show="saved" x-transition.opacity.duration.200ms class="text-xs text-emerald-400">{{ __('Settings saved.') }}</span>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
