<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3 flex-wrap">
            <button type="button" wire:click="$set('tab', 'current')" class="px-3 py-2 text-sm rounded-md {{ $tab === 'current' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300' }}">
                Current Issues ({{ $openCount }})
            </button>
            <button type="button" wire:click="$set('tab', 'resolved')" class="px-3 py-2 text-sm rounded-md {{ $tab === 'resolved' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300' }}">
                Resolved Issues ({{ $resolvedCount }})
            </button>
        </div>
        <button type="button" wire:click="sync" wire:loading.attr="disabled" class="px-3 py-2 text-sm rounded-md border border-indigo-300 text-indigo-600 hover:text-indigo-800 dark:border-indigo-500/50 dark:text-indigo-300 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
            <x-loading-spinner target="sync" />
            Sync Alerts
        </button>
    </div>
    @if (! $sslVerifyEnabled)
        <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-200">
            GitHub SSL verification is disabled. Sync runs without certificate validation.
        </div>
    @endif

    <div class="space-y-4">
        @forelse ($alerts as $alert)
            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            {{ $alert->package_name ?? 'Unknown package' }}
                        </div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $alert->ecosystem ?? 'unknown ecosystem' }}
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            {{ $alert->state }}
                        </span>
                        @if ($alert->severity)
                            <span class="px-2 py-1 rounded-full bg-rose-500/10 text-rose-300">
                                {{ strtoupper($alert->severity) }}
                            </span>
                        @endif
                    </div>
                </div>
                @if ($alert->advisory_summary)
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $alert->advisory_summary }}</p>
                @endif
                <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-400 dark:text-slate-500">
                    @if ($alert->fixed_in)
                        <span>Fixed in: {{ $alert->fixed_in }}</span>
                    @endif
                    @if ($alert->alert_created_at)
                        <span>Created: {{ \App\Support\DateFormatter::forUser($alert->alert_created_at, 'M j, Y') }}</span>
                    @endif
                    @if ($alert->fixed_at)
                        <span>Fixed: {{ \App\Support\DateFormatter::forUser($alert->fixed_at, 'M j, Y') }}</span>
                    @endif
                </div>
                <div class="mt-3 flex flex-wrap gap-3 text-sm">
                    @if ($alert->advisory_url)
                        <a href="{{ $alert->advisory_url }}" target="_blank" class="text-indigo-600 dark:text-indigo-300">Advisory</a>
                    @endif
                    @if ($alert->html_url)
                        <a href="{{ $alert->html_url }}" target="_blank" class="text-indigo-600 dark:text-indigo-300">Dependabot Report</a>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-slate-500 dark:text-slate-400">No issues found for this tab.</p>
        @endforelse
    </div>
</div>
