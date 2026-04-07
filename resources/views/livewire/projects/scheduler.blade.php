<div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @include('livewire.projects.partials.tabs')

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/60 p-6 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $schedulerHealthy ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' }}">
                        {{ $schedulerHealthy ? 'Healthy' : 'Not detected' }}
                    </span>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Scheduler Status</h3>
                </div>
                <div class="text-sm text-slate-600 dark:text-slate-300 space-y-1">
                    <div>
                        Last heartbeat:
                        <span class="text-slate-900 dark:text-slate-100">
                            {{ \App\Support\DateFormatter::forUser($lastHeartbeat, 'M j, Y g:i a', 'Never') }}
                        </span>
                    </div>
                    <div>
                        Last source:
                        <span class="text-slate-900 dark:text-slate-100">
                            {{ $lastSource ?? 'Unknown' }}
                        </span>
                    </div>
                    <div>
                        Last manual run:
                        <span class="text-slate-900 dark:text-slate-100">
                            {{ \App\Support\DateFormatter::forUser($lastManualRun, 'M j, Y g:i a', 'Never') }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/60 p-6 space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Queue Health</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Queued deployments: <span class="text-slate-900 dark:text-slate-100">{{ $queueCount }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="refreshStatus" class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white inline-flex items-center">
                        <x-loading-spinner target="refreshStatus" />
                        Refresh Status
                    </button>
                    <button type="button" wire:click="runScheduler" class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white inline-flex items-center">
                        <x-loading-spinner target="runScheduler" />
                        Run Scheduler Now
                    </button>
                    <button type="button" wire:click="processQueue" class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white inline-flex items-center">
                        <x-loading-spinner target="processQueue" />
                        Process Queue Now
                    </button>
                </div>
                @if (! $queueEnabled)
                    <div class="text-xs text-amber-700 dark:text-amber-200">
                        Deploy queue is disabled in configuration.
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/60 p-6 space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Cron Setup</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Add the cron entry below to run the Laravel scheduler every minute.
                </p>
            </div>
            <div class="rounded-md border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-950/40 p-3 text-xs font-mono text-slate-700 dark:text-slate-200">
                {{ $cronCommand }}
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="installCron" class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white inline-flex items-center">
                    <x-loading-spinner target="installCron" />
                    Install Cron (Best Effort)
                </button>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                If automatic install fails, add the cron line manually or configure a Task Scheduler entry on Windows.
            </p>
        </div>

        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/60 p-6 space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Scheduler Error Log</h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Entries are recorded only when the scheduler reports an issue. Repeated errors increase the count.
                </p>
            </div>

            @if (empty($schedulerLog))
                <div class="text-sm text-slate-500 dark:text-slate-400">
                    No scheduler errors recorded yet.
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($schedulerLog as $entry)
                        <div class="rounded-md border border-slate-200 dark:border-slate-800 bg-white/70 dark:bg-slate-950/40 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ $entry['message'] ?? 'Scheduler error' }}
                                </div>
                                <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    Error Count: {{ $entry['count'] ?? 1 }}
                                </div>
                            </div>
                            <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                First seen: {{ $entry['first_seen'] ?? 'Unknown' }} · Last seen: {{ $entry['last_seen'] ?? 'Unknown' }}
                            </div>
                            @if (! empty($entry['output']))
                                <pre
                                    class="mt-3 max-h-48 overflow-auto rounded-md border border-slate-200/70 dark:border-slate-800 bg-slate-100/70 dark:bg-slate-900/60 p-3 text-xs text-slate-700 dark:text-slate-200 whitespace-pre-wrap"
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
    </div>
</div>
