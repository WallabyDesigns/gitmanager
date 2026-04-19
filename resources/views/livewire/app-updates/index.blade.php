@php
    $reverseLog = function (?string $text): ?string {
        if ($text === null || $text === '') {
            return null;
        }
        return $text;
    };
@endphp

<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs')
            <div class="space-y-6">
                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Update Status</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Check whether this install is on the latest Git Web Manager version.</p>
                </div>
                <div class="flex flex-wrap gap-2 items-center">
                    @php($status = $updateStatus['status'] ?? 'unknown')
                    <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $status === 'up-to-date' ? 'bg-emerald-500/20 text-emerald-200' : ($status === 'update-available' ? 'bg-amber-500/20 text-amber-200' : ($status === 'disabled' ? 'bg-slate-500/20 text-slate-200' : 'bg-slate-500/20 text-slate-200')) }}">
                        {{ $status === 'up-to-date' ? 'UP TO DATE' : ($status === 'update-available' ? 'UPDATE AVAILABLE' : ($status === 'disabled' ? 'CHECKS DISABLED' : 'UNKNOWN')) }}
                    </span>
                    <button
                        type="button"
                        wire:click="refreshUpdateStatus"
                        wire:loading.attr="disabled"
                        @if (! $checkUpdatesEnabled) disabled @endif
                        class="px-3 py-1.5 rounded-md border border-slate-300 text-slate-600 text-sm hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center"
                    >
                        <x-loading-spinner target="refreshUpdateStatus" />
                        Check Now
                    </button>
                </div>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                    <div class="text-xs uppercase text-slate-400 dark:text-slate-500">Current</div>
                    <div class="font-mono text-slate-700 dark:text-slate-200">{{ $updateStatus['current'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-slate-400 dark:text-slate-500">Latest</div>
                    <div class="font-mono text-slate-700 dark:text-slate-200">{{ $updateStatus['latest'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-slate-400 dark:text-slate-500">Branch</div>
                    <div class="text-slate-700 dark:text-slate-200">{{ $updateStatus['branch'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-slate-400 dark:text-slate-500">Checked</div>
                    <div class="text-slate-700 dark:text-slate-200">{{ \App\Support\DateFormatter::forUser($updateStatus['checked_at'] ?? null, 'M j, Y g:i a', '—') }}</div>
                </div>
            </div>
            @php($enterprisePackage = $updateStatus['enterprise_package'] ?? null)
            @if (is_array($enterprisePackage))
                @php($enterpriseStatus = $enterprisePackage['status'] ?? 'unknown')
                <div class="mt-4 rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-950/40 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-sm font-semibold text-slate-800 dark:text-slate-100">Enterprise Package</div>
                        <span class="px-2 py-1 rounded-full text-xs font-semibold {{ $enterpriseStatus === 'up-to-date' ? 'bg-emerald-500/20 text-emerald-200' : ($enterpriseStatus === 'update-available' ? 'bg-amber-500/20 text-amber-200' : ($enterpriseStatus === 'disabled' ? 'bg-slate-500/20 text-slate-200' : 'bg-slate-500/20 text-slate-200')) }}">
                            {{ strtoupper(str_replace('-', ' ', $enterpriseStatus)) }}
                        </span>
                    </div>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2 text-xs text-slate-600 dark:text-slate-300">
                        <div>
                            <div class="uppercase tracking-wide text-slate-400 dark:text-slate-500">Current</div>
                            <div class="font-mono text-slate-700 dark:text-slate-200">{{ $enterprisePackage['current'] ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="uppercase tracking-wide text-slate-400 dark:text-slate-500">Latest</div>
                            <div class="font-mono text-slate-700 dark:text-slate-200">{{ $enterprisePackage['latest'] ?? '—' }}</div>
                        </div>
                    </div>
                    @if (! empty($enterprisePackage['message']))
                        <div class="mt-2 text-xs {{ $enterpriseStatus === 'update-available' ? 'text-amber-500 dark:text-amber-300' : 'text-slate-500 dark:text-slate-400' }}">
                            {{ $enterprisePackage['message'] }}
                        </div>
                    @endif
                </div>
            @endif
            @if (! empty($updateStatus['error']))
                <div class="mt-3 text-xs text-rose-400">
                    {{ $updateStatus['error'] }}
                </div>
            @endif
            @if (! $checkUpdatesEnabled)
                <div class="mt-3 text-xs text-slate-400 dark:text-slate-500">
                    Update checks are disabled in System Settings.
                </div>
            @endif
        </div>

                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Run Update</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">This will pull from the `wallabydesigns/gitmanager` repo and apply updates locally.</p>
                    <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                        Auto-updates are {{ $autoUpdateEnabled ? 'enabled' : 'disabled' }} in System Settings.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="runUpdate"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center"
                    >
                        <x-loading-spinner target="runUpdate" />
                        Update App
                    </button>
                    @if ($latest && $latest->status === 'failed')
                        <button
                        type="button"
                        wire:click="runForceUpdate"
                        wire:loading.attr="disabled"
                        onclick="return confirm('Force update will discard local code changes and re-sync with the remote repo (protected files like .env are preserved). Continue?')"
                        class="px-4 py-2 rounded-md border border-rose-500/70 text-rose-200 text-sm hover:text-white hover:bg-rose-500/10 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center"
                    >
                            <x-loading-spinner target="runForceUpdate" />
                            Force Update
                        </button>
                    @endif
                </div>
            </div>
            @if (! empty($pendingChanges))
                @php($changeLogOpen = ($updateStatus['status'] ?? '') === 'update-available')
                <details class="mt-4 rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-950/40 p-4" @if ($changeLogOpen) open @endif>
                    <summary class="cursor-pointer text-sm font-semibold text-slate-700 dark:text-slate-200">
                        What's changed since your last update
                        <span class="text-xs font-normal text-slate-500 dark:text-slate-400">({{ count($pendingChanges) }} commits)</span>
                    </summary>
                    <div class="mt-3 text-sm text-slate-600 dark:text-slate-300">
                        <ul class="mt-2 space-y-2 text-xs text-slate-600 dark:text-slate-300">
                            @foreach ($pendingChanges as $change)
                                <li class="flex gap-2">
                                    <span class="font-mono text-slate-500 dark:text-slate-400">{{ $change['hash'] }}</span>
                                    <span class="text-slate-700 dark:text-slate-200 break-words">{{ $change['subject'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </details>
            @endif
            @if (! $checkUpdatesEnabled)
                <div class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                    Manual updates are always available, even when update checks are off.
                </div>
            @endif
            <div class="mt-3 text-xs text-slate-400 dark:text-slate-500" wire:loading>
                Update running... this can take a few minutes.
            </div>
            <div class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                Local changes are preserved automatically when detected.
            </div>
            <div class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                Force Update does not clear app data (storage, .env, logs, or excluded paths are preserved).
            </div>
        </div>

                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Latest Update</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Most recent update attempt for this app.</p>
                    </div>
                    @if ($latest)
                        @php($latestWarning = $latest->status === 'warning' || ($latest->status === 'failed' && str_contains($latest->output_log ?? '', 'stashed changes could not be restored')))
                        <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $latestWarning ? 'bg-amber-500/20 text-amber-200' : ($latest->status === 'success' ? 'bg-emerald-500/20 text-emerald-200' : ($latest->status === 'failed' ? 'bg-rose-500/20 text-rose-200' : 'bg-slate-500/20 text-slate-200')) }}">
                            {{ $latestWarning ? 'WARNING' : strtoupper($latest->status) }}
                        </span>
                    @endif
                </div>

                @if (! $latest)
                    <p class="text-sm text-slate-500 dark:text-slate-400">No update attempts yet.</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-2 text-sm">
                        <div>
                            <div class="text-xs uppercase text-slate-400 dark:text-slate-500">Started</div>
                            <div class="text-slate-700 dark:text-slate-200">{{ \App\Support\DateFormatter::forUser($latest->started_at, 'M j, Y g:i A', '—') }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-slate-400 dark:text-slate-500">Finished</div>
                            <div class="text-slate-700 dark:text-slate-200">{{ \App\Support\DateFormatter::forUser($latest->finished_at, 'M j, Y g:i A', '—') }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-slate-400 dark:text-slate-500">From</div>
                            <div class="font-mono text-slate-700 dark:text-slate-200">{{ $latest->from_hash ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-slate-400 dark:text-slate-500">To</div>
                            <div class="font-mono text-slate-700 dark:text-slate-200">{{ $latest->to_hash ?? '—' }}</div>
                        </div>
                    </div>

                    <div>
                        <div class="text-xs uppercase text-slate-400 dark:text-slate-500">Output</div>
                        <pre
                            class="mt-2 max-h-80 overflow-auto rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-950/70 p-4 text-xs text-slate-200 whitespace-pre-wrap break-words"
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
                        >{{ $reverseLog($latest->output_log) ?? 'No output captured.' }}</pre>
                    </div>
                @endif
            </div>
        </div>

                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Recent Updates</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Last 10 update attempts for this app.</p>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($recent as $update)
                    <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                        @php($updateWarning = $update->status === 'warning' || ($update->status === 'failed' && str_contains($update->output_log ?? '', 'stashed changes could not be restored')))
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                {{ \App\Support\DateFormatter::forUser($update->started_at, 'M j, Y g:i a', 'Queued') }}
                            </div>
                            <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $updateWarning ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : ($update->status === 'success' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($update->status === 'failed' ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300')) }}">
                                {{ $updateWarning ? 'warning' : $update->status }}
                            </span>
                        </div>
                        <div class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                            {{ $update->from_hash ?? '—' }} → {{ $update->to_hash ?? '—' }}
                        </div>
                        @if ($update->output_log)
                            <details class="mt-3">
                                <summary class="cursor-pointer text-xs text-indigo-600 dark:text-indigo-300">View log</summary>
                                <pre
                                    class="mt-2 max-h-80 overflow-auto text-xs text-slate-600 dark:text-slate-300 whitespace-pre-wrap break-words bg-slate-50 dark:bg-slate-950/40 rounded-lg p-3 border border-slate-200/70 dark:border-slate-800"
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
                                >{{ $reverseLog($update->output_log) }}</pre>
                            </details>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500 dark:text-slate-400">No update attempts yet.</p>
                @endforelse
            </div>
                </div>
            </div>
        </div>
    </div>
</div>
