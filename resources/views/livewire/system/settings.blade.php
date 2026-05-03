<div class="py-10" wire:init="loadData">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs', ['systemTab' => $settingsSection])

            <div class="space-y-6">
                @if ($settingsSection === 'scheduler')
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
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Task Frequency</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                Keep the cron running every minute. These controls decide how often each recurring task actually executes.
                            </p>
                        </div>

                        <div class="space-y-4">
                            @foreach ($schedulerTaskDefinitions as $task => $definition)
                                @php
                                    $taskStatus = $schedulerTaskStatuses[$task] ?? ['enabled' => true, 'label' => 'Enabled'];
                                @endphp
                                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-950/40 p-4">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $definition['label'] }}</div>
                                                <span class="px-2 py-1 rounded-full text-[11px] font-semibold uppercase tracking-wide {{ ($taskStatus['enabled'] ?? false) ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-500/20 text-slate-200' }}">
                                                    {{ $taskStatus['label'] ?? (($taskStatus['enabled'] ?? false) ? 'Enabled' : 'Disabled') }}
                                                </span>
                                            </div>
                                            <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $definition['description'] }}</div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="number"
                                                min="1"
                                                max="{{ ($schedulerTaskIntervals[$task]['unit'] ?? $definition['default_unit']) === 'hours' ? 24 : 59 }}"
                                                wire:model.defer="schedulerTaskIntervals.{{ $task }}.value"
                                                class="w-24 rounded-md border border-slate-200/70 bg-white/70 px-3 py-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                            />
                                            <select
                                                wire:model.defer="schedulerTaskIntervals.{{ $task }}.unit"
                                                class="w-24 rounded-md border border-slate-200/70 bg-white/70 px-3 py-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
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

                    <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Stored Log Cleanup</h3>
                            <span class="px-2 py-1 rounded-full text-[11px] font-semibold uppercase tracking-wide {{ $logCleanupEnabled ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-500/20 text-slate-200' }}">
                                {{ $logCleanupEnabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Clears old deployment and system update console logs from the database while keeping the history rows themselves.
                        </p>

                        <label class="flex items-start gap-3">
                            <input type="checkbox" wire:model="logCleanupEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                            <span class="text-sm text-slate-600 dark:text-slate-300">
                                Enable automatic stored log cleanup
                                <span class="block text-xs text-slate-400 dark:text-slate-500">Runs once per day when enabled.</span>
                            </span>
                        </label>

                        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-950/40 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Retention Window</div>
                                    <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">Remove stored logs older than this many days.</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input
                                        type="number"
                                        min="1"
                                        max="3650"
                                        wire:model.defer="logRetentionDays"
                                        class="w-28 rounded-md border border-slate-200/70 bg-white/70 px-3 py-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    />
                                    <span class="rounded-md border border-slate-200/70 bg-white/70 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300">Days</span>
                                </div>
                            </div>
                            <x-input-error :messages="$errors->get('logRetentionDays')" class="mt-3" />
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="runLogCleanup" class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white inline-flex items-center">
                                <x-loading-spinner target="runLogCleanup" />
                                Run Cleanup Now
                            </button>
                            <button
                                type="button"
                                wire:click="clearAllStoredLogs"
                                onclick="return confirm('This will clear all stored deployment, self-update, and scheduler error logs. Continue?')"
                                class="px-3 py-2 text-xs rounded-md border border-rose-300/70 text-rose-700 hover:text-rose-900 dark:border-rose-500/40 dark:text-rose-200 dark:hover:text-white inline-flex items-center"
                            >
                                <x-loading-spinner target="clearAllStoredLogs" />
                                Clear All Stored Logs
                            </button>
                        </div>
                        <div class="text-xs text-slate-400 dark:text-slate-500">
                            Manual cleanup keeps deployment and update status history, but removes large console output and scheduler error logs. The Clear All action also runs SQLite VACUUM when supported so disk space can be reclaimed.
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
                        @if (! ($schedulerRuntime['ok'] ?? true))
                            <div class="rounded-md border border-rose-300/70 bg-rose-50/80 dark:border-rose-500/40 dark:bg-rose-500/10 p-4 space-y-2">
                                <div class="text-sm font-semibold text-rose-900 dark:text-rose-200">Scheduler PHP Runtime Issue</div>
                                <p class="text-xs text-rose-800 dark:text-rose-200">
                                    {{ $schedulerRuntime['message'] ?? 'The configured PHP binary failed the scheduler preflight.' }}
                                </p>
                                <div class="text-xs text-rose-800 dark:text-rose-200">
                                    Configured PHP: <code class="font-mono">{{ $schedulerRuntime['php_binary'] ?? 'php' }}</code>
                                    @if (! empty($schedulerRuntime['resolved_binary']))
                                        <span class="mx-1">&middot;</span>
                                        Resolved: <code class="font-mono">{{ $schedulerRuntime['resolved_binary'] }}</code>
                                    @endif
                                    @if (! empty($schedulerRuntime['version']))
                                        <span class="mx-1">&middot;</span>
                                        PHP {{ $schedulerRuntime['version'] }}
                                    @endif
                                </div>
                                <p class="text-xs text-rose-700 dark:text-rose-300">
                                    Install/enable the missing extension for CLI PHP, or set <code class="font-mono">GWM_PHP_BINARY</code> to a PHP binary that includes it.
                                </p>
                            </div>
                        @else
                            <div class="rounded-md border border-emerald-200/70 bg-emerald-50/60 dark:border-emerald-500/30 dark:bg-emerald-500/10 p-3 text-xs text-emerald-800 dark:text-emerald-200">
                                Scheduler PHP preflight passed for <code class="font-mono">{{ $schedulerRuntime['php_binary'] ?? 'php' }}</code>.
                            </div>
                        @endif
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
                @endif

                @if ($settingsSection === 'application')
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

                    @if ($isLocalInstall)
                        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">SSL / Connection Repair</h3>
                                <p class="text-sm text-slate-500 dark:text-slate-400">
                                    Fixes cURL error 60 (missing CA certificate bundle) for local installations. Enables a TLS bypass so outbound license verification requests succeed when your host lacks a valid CA trust store.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                <button
                                    type="button"
                                    wire:click="runLocalLicenseRepair"
                                    class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white inline-flex items-center"
                                >
                                    <x-loading-spinner target="runLocalLicenseRepair" />
                                    Run Best-Effort SSL Fix
                                </button>
                                @if ($localLicenseTlsBypassEnabled)
                                    <span class="text-xs text-emerald-500 dark:text-emerald-400">Local TLS bypass is active.</span>
                                @else
                                    <span class="text-xs text-slate-400 dark:text-slate-500">Local TLS bypass is not yet enabled.</span>
                                @endif
                            </div>
                            <p class="text-xs text-slate-400 dark:text-slate-500">
                                Only available on local installs. This setting does not affect production SSL behaviour.
                            </p>
                        </div>
                    @endif

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
                @endif

                @if ($settingsSection === 'audits')
                    <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Audit Checks</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Run scheduled project and container audits and track issues in System Security.</p>
                        </div>
                        @if ($isEnterprise)
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="auditEnabled" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-slate-600 dark:text-slate-300">
                                    Enable automatic audits
                                    <span class="block text-xs text-slate-400 dark:text-slate-500">Runs npm/composer project audits and managed container runtime checks on the scheduler.</span>
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
                        @else
                            <div class="rounded-lg border border-amber-300/60 bg-amber-50/70 dark:border-amber-500/30 dark:bg-amber-500/10 p-4">
                                <div class="text-sm font-semibold text-amber-800 dark:text-amber-200">Enterprise Feature</div>
                                <p class="mt-1 text-xs text-amber-700 dark:text-amber-200">
                                    Automatic project and container audits are available on Enterprise.
                                    Upgrade to unlock hourly audit automation and alerting.
                                </p>
                                <button
                                    type="button"
                                    onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Automatic Project & Container Audits' } }));"
                                    class="mt-3 inline-flex items-center gap-2 rounded-md border border-amber-300/70 px-3 py-1.5 text-xs font-semibold text-amber-700 hover:text-amber-800 dark:border-amber-500/60 dark:text-amber-300 dark:hover:text-amber-200"
                                >
                                    Get Enterprise
                                </button>
                            </div>
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
                @endif

                @if ($settingsSection === 'licensing')
                    @php
                        $licenseStatusLower = strtolower((string) ($licenseState['status'] ?? 'missing'));
                        $showLocalLicenseWarning = $isLocalInstall && $licenseStatusLower !== 'valid';
                        $licenseMessage = trim((string) ($licenseState['message'] ?? ''));
                        $licenseMessageLower = strtolower($licenseMessage);

                        $isTlsError = str_contains($licenseMessageLower, 'ssl') || str_contains($licenseMessageLower, 'curl error 60');
                        if ($isTlsError) {
                            $localWarningDetail = 'TLS trust is not configured for this host. Run SSL / Connection Repair on the App & Security tab, then re-verify.';
                        } else {
                            $localWarningDetail = 'License verification must pass before this installation can unlock Enterprise features.';
                        }
                    @endphp

                    @if ($showLocalLicenseWarning)
                        <div class="rounded-xl border border-amber-300/70 bg-amber-50/70 dark:border-amber-500/40 dark:bg-amber-500/10 p-4 space-y-3">
                            <div class="space-y-1">
                                <div class="text-sm font-semibold text-amber-900 dark:text-amber-200">Local Installation Notice</div>
                                <p class="text-xs text-amber-800 dark:text-amber-200">{{ $localWarningDetail }}</p>
                            </div>
                            @if ($localLicenseTlsBypassEnabled)
                                <p class="text-xs text-emerald-700 dark:text-emerald-400">
                                    ✓ Local TLS bypass is active — outbound HTTPS connections are allowed without a CA bundle.
                                </p>
                            @endif
                            @if ($licenseMessage !== '')
                                <p class="text-xs text-amber-700 dark:text-amber-300">{{ $licenseMessage }}</p>
                            @endif
                            @if ($isTlsError)
                                <p class="text-xs text-amber-700 dark:text-amber-300">
                                    Use <a href="{{ route('system.application') }}" class="underline">App &amp; Security → SSL / Connection Repair</a> to fix this automatically.
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                        @php
                            $licenseStatus = (string) ($licenseState['status'] ?? 'missing');
                            $licenseBadgeClass = match ($licenseStatus) {
                                'valid' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
                                'invalid' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
                                'unverified' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
                                default => 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-300',
                            };
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Enterprise License</h3>
                                <p class="text-sm text-slate-500 dark:text-slate-400">License administration handled by Git Web Manager, this panel only verifies the license. If you have your license key, enter it below.</p>
                            </div>
                            @if($licenseStatus !== 'missing')
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $licenseBadgeClass }}">
                                    {{ $licenseStatus }}
                                </span>
                            @endif
                        </div>

                        <div class="space-y-2 text-xs text-slate-500 dark:text-slate-400">
                            @if(!empty($licenseState['configured']))
                                <div>Configured: {{ ! empty($licenseState['configured']) ? 'Yes' : 'No' }}</div>
                            @endif
                            <div>Licensed Edition: {{ ucfirst((string) ($licenseState['edition'] ?? 'community')) }}</div>
                            <div>Package Version: {{ $systemPackageVersion }}</div>
                            @if(isset($licenseState['installation_uuid']))
                                <div>Installation UUID: {{ $licenseState['installation_uuid'] ?? 'Unknown' }}</div>
                            @endif
                            @if(isset($licenseState['message']) && $licenseState['message'] != "No license key configured.")
                                <div>Message: {{ $licenseState['message'] ?? 'No license state available.' }}</div>
                            @endif
                            @if(isset($licenseState['bound_ip']))
                                <div>Bound IP: {{ $licenseState['bound_ip'] ?? 'Unknown' }}</div>
                            @endif
                            @if(isset($licenseState['detected_ip']))
                                <div>Detected IP: {{ $licenseState['detected_ip'] ?? 'Unknown' }}</div>
                            @endif
                            @if(isset($licenseState['verified_at']))
                                <div>Verified At: {{ \App\Support\DateFormatter::forUser($licenseState['verified_at'] ?? null, 'M j, Y g:i a', 'Never') }}</div>
                            @endif
                            @if(isset($licenseState['expires_at']))
                                <div>Expires At: {{ \App\Support\DateFormatter::forUser($licenseState['expires_at'] ?? null, 'M j, Y g:i a', 'Not provided') }}</div>
                            @endif
                            @if(isset($licenseState['grace_ends_at']))
                                <div>Grace Ends At: {{ \App\Support\DateFormatter::forUser($licenseState['grace_ends_at'] ?? null, 'M j, Y g:i a', 'Not provided') }}</div>
                            @endif
                        </div>

                        <div>
                            <label class="text-xs uppercase tracking-wide text-slate-500">License Key</label>
                            <input type="text" wire:model.lazy="enterpriseLicenseKey" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-xs text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Enter new license key to set or rotate" />
                            <x-input-error :messages="$errors->get('enterpriseLicenseKey')" class="mt-2" />
                            <p class="mt-1 text-xs text-slate-500">Leave blank to keep the current saved key.</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if (! $isEnterprise || $licenseStatusLower !== 'valid')
                                <button
                                    type="button"
                                    onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Edition' } }));"
                                    class="px-3 py-2 text-xs rounded-md border border-amber-300/70 text-amber-700 hover:text-amber-800 dark:border-amber-500/60 dark:text-amber-300 dark:hover:text-amber-200 inline-flex items-center"
                                >
                                    Get Enterprise
                                </button>
                            @endif
                            <button type="button" wire:click="verifyLicense" class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white inline-flex items-center">
                                <x-loading-spinner target="verifyLicense" />
                                Verify License Now
                            </button>
                            <button type="button" wire:click="clearLicense" onclick="return confirm('Clear the saved license key and cached state?') || event.stopImmediatePropagation()" class="px-3 py-2 text-xs rounded-md border border-rose-500/60 text-rose-700 hover:text-rose-900 dark:text-rose-300 dark:hover:text-rose-100 inline-flex items-center">
                                <x-loading-spinner target="clearLicense" />
                                Clear License
                            </button>
                        </div>
                    </div>
                @endif

                @if ($settingsSection === 'environment')
                    <div class="space-y-6">
                        {{-- GWM Keys --}}
                        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">GWM Environment Variables</h3>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">All <code class="font-mono">GWM_*</code> keys from your <code class="font-mono">.env</code> file. Changes take effect immediately.</p>
                                </div>
                            </div>
                            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                @forelse ($gwmKeys as $key => $meta)
                                    <div class="px-6 py-4 grid grid-cols-1 lg:grid-cols-[1fr,auto] gap-3 items-start">
                                        <div class="space-y-1 min-w-0">
                                            <div class="text-xs font-mono font-semibold text-indigo-600 dark:text-indigo-400">{{ $key }}</div>
                                            @if ($meta['description'])
                                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ $meta['description'] }}</div>
                                            @endif
                                            <input
                                                wire:model="gwmEdits.{{ $key }}"
                                                type="{{ str_contains(strtolower($key), 'password') || str_contains(strtolower($key), 'secret') ? 'password' : 'text' }}"
                                                placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                class="mt-1.5 w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm font-mono text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-600 focus:border-indigo-400 focus:outline-none"
                                            >
                                            @if ($meta['default'] !== '' && ($gwmEdits[$key] ?? '') === '')
                                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Default: <code class="font-mono">{{ $meta['default'] }}</code></p>
                                            @endif
                                        </div>
                                        <button
                                            wire:click="saveEnvKey('{{ $key }}')"
                                            class="shrink-0 mt-6 lg:mt-7 px-3 py-1.5 rounded-md border border-indigo-400/60 text-xs font-semibold text-indigo-600 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 transition inline-flex items-center gap-1"
                                        >
                                            <x-loading-spinner target="saveEnvKey('{{ $key }}')" />
                                            Save
                                        </button>
                                    </div>
                                @empty
                                    <div class="px-6 py-8 text-sm text-slate-500 dark:text-slate-400 text-center">No GWM_* variables found in your .env file.</div>
                                @endforelse
                            </div>
                        </div>

                        {{-- Env Backups --}}
                        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
                                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Environment Backups</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Snapshots of your <code class="font-mono">.env</code> file. Restore any backup to roll back configuration changes. The current file is saved automatically before each restore.</p>
                            </div>
                            <div class="px-6 py-4 flex items-center gap-3 border-b border-slate-100 dark:border-slate-800">
                                <input wire:model="envBackupLabel" type="text" placeholder="Label (optional)"
                                        class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-900 dark:text-slate-100 focus:border-indigo-400 focus:outline-none w-48">
                                <button wire:click="createEnvBackup" class="px-3 py-1.5 text-xs rounded-md border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:border-indigo-300 hover:text-indigo-600 transition inline-flex items-center gap-1">
                                    <x-loading-spinner target="createEnvBackup" />
                                    Create Backup
                                </button>
                            </div>
                            @if (count($envBackups) > 0)
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-slate-50 dark:bg-slate-800/50">
                                            <tr>
                                                @foreach (['Filename', 'Created', 'Size', 'Actions'] as $h)
                                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ $h }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            @foreach ($envBackups as $backup)
                                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                                    <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $backup['filename'] }}</td>
                                                    <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ $backup['created_at'] }}</td>
                                                    <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ number_format($backup['size'] / 1024, 1) }} KB</td>
                                                    <td class="px-4 py-3">
                                                        <div class="flex items-center gap-2">
                                                            <button
                                                                wire:click="restoreEnvBackup('{{ $backup['filename'] }}')"
                                                                wire:confirm="Restore this backup? Your current .env will be saved first."
                                                                class="text-xs px-2 py-1 rounded border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:border-indigo-300 hover:text-indigo-600 transition">
                                                                Restore
                                                            </button>
                                                            <button
                                                                wire:click="deleteEnvBackup('{{ $backup['filename'] }}')"
                                                                wire:confirm="Delete this backup permanently?"
                                                                class="text-xs px-2 py-1 rounded border border-slate-200 dark:border-slate-700 text-slate-500 hover:border-rose-300 hover:text-rose-600 transition">
                                                                Delete
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <div class="px-6 py-8 text-center text-sm text-slate-500 dark:text-slate-400">No backups yet. Create one above.</div>
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
                    <span wire:dirty class="text-xs text-amber-400">Settings are unsaved.</span>
                    <span x-show="saved" x-transition.opacity.duration.200ms class="text-xs text-emerald-400">Settings saved.</span>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
