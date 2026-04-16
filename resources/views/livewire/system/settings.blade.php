<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @include('livewire.system.partials.tabs')

        <div id="scheduler-settings" class="grid gap-6 lg:grid-cols-2 scroll-mt-24">
            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-3">
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

            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Queue Health</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Queued tasks: <span class="text-slate-900 dark:text-slate-100">{{ $queueCount }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="refreshSchedulerStatus" class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white inline-flex items-center">
                        <x-loading-spinner target="refreshSchedulerStatus" />
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
                        Task queue is disabled in configuration.
                    </div>
                @endif
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Cron Setup</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">
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

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Scheduler Error Log</h3>
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

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Update Preferences</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Control how Git Web Manager checks for and applies updates.</p>
            </div>

            <div class="space-y-4">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="checkUpdates" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                    <span class="text-sm text-slate-600 dark:text-slate-300">
                        Check for updates
                        <span class="block text-xs text-slate-400 dark:text-slate-500">Shows update availability in the System area and navigation.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="autoUpdate" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                    <span class="text-sm text-slate-600 dark:text-slate-300">
                        Perform auto-updates
                        <span class="block text-xs text-slate-400 dark:text-slate-500">Runs the nightly self-update schedule when enabled.</span>
                    </span>
                </label>
                <div class="text-xs {{ $autoUpdate ? 'text-emerald-400' : 'text-slate-400 dark:text-slate-500' }}">
                    {{ $autoUpdate ? 'Auto-updates are enabled.' : 'Auto-updates are disabled. Manual updates remain available in System Updates.' }}
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">GitHub Security</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Control SSL verification for GitHub API calls.</p>
            </div>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="githubSslVerify" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                <span class="text-sm text-slate-600 dark:text-slate-300">
                    Verify GitHub SSL certificates
                    <span class="block text-xs text-slate-400 dark:text-slate-500">Disable only if your host is missing CA certificates.</span>
                </span>
            </label>
            @if (! $githubSslVerify)
                <div class="text-xs text-rose-400">Warning: SSL verification is disabled. GitHub API calls are less secure.</div>
            @endif
        </div>

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Audit Checks</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Run npm/composer audits after update checks and track issues in System Security.</p>
            </div>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="auditEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                <span class="text-sm text-slate-600 dark:text-slate-300">
                    Enable audit checks
                    <span class="block text-xs text-slate-400 dark:text-slate-500">Runs dependency audits during the scheduled update checks.</span>
                </span>
            </label>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="auditEmailEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                <span class="text-sm text-slate-600 dark:text-slate-300">
                    Email audit summaries
                    <span class="block text-xs text-slate-400 dark:text-slate-500">Sends a consolidated report when issues are found or resolved.</span>
                </span>
            </label>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="auditAutoCommit" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                <span class="text-sm text-slate-600 dark:text-slate-300">
                    Auto-commit resolved audit fixes
                    <span class="block text-xs text-slate-400 dark:text-slate-500">Pushes dependency lockfile changes when audits resolve all vulnerabilities.</span>
                </span>
            </label>
            @if (! $mailConfigured)
                <div class="text-xs text-rose-400">Email is not configured. Set SMTP details in System → Email Settings to enable audit emails.</div>
            @endif
            @if (! $auditEnabled)
                <div class="text-xs text-slate-400 dark:text-slate-500">Scheduled audits are disabled.</div>
            @endif
        </div>

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Health Alerts</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Email notifications when projects go offline during automatic checks.</p>
            </div>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="healthEmailEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                <span class="text-sm text-slate-600 dark:text-slate-300">
                    Send email alerts when health checks fail
                    <span class="block text-xs text-slate-400 dark:text-slate-500">Only automatic checks trigger emails. Manual checks never send alerts.</span>
                </span>
            </label>
            @if (! $mailConfigured)
                <div class="text-xs text-rose-400">Email is not configured. Set SMTP details in System → Email Settings to enable health alerts.</div>
            @endif
            @if (! $healthEmailEnabled)
                <div class="text-xs text-slate-400 dark:text-slate-500">Health alert emails are disabled.</div>
            @endif
        </div>

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Timezone</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Sets the default timezone used throughout the app.</p>
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Timezone</label>
                <select wire:model="timezone" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    @foreach ($timezones as $tz)
                        <option value="{{ $tz }}">{{ $tz }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3" x-data="{ saved: false, timer: null }" x-on:settings-saved.window="
            saved = true;
            clearTimeout(timer);
            timer = setTimeout(() => saved = false, 2000);
        ">
            <button type="button" wire:click="save" wire:loading.attr="disabled" disabled wire:dirty.remove.attr="disabled" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center">
                <x-loading-spinner target="save" />
                Save Settings
            </button>
            <span wire:dirty class="text-xs text-amber-400">Settings are unsaved.</span>
            <span x-show="saved" x-transition.opacity.duration.200ms class="text-xs text-emerald-400">Settings saved.</span>
        </div>
    </div>
</div>
