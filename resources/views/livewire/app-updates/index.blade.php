<div class="py-10">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Run Update</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">This will pull from the `Costigan-Stephen/gitmanager` repo and apply updates locally.</p>
                    @if (! $selfUpdateEnabled)
                        <p class="mt-2 text-sm text-rose-500">Self-update is currently disabled in configuration.</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="runUpdate"
                        wire:loading.attr="disabled"
                        @if (! $selfUpdateEnabled) disabled @endif
                        class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        Update App
                    </button>
                    <button
                        type="button"
                        wire:click="runUpdatePreserve"
                        wire:loading.attr="disabled"
                        @if (! $selfUpdateEnabled) disabled @endif
                        class="px-4 py-2 rounded-md border border-slate-300 text-slate-600 text-sm hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        Update (Preserve Local Changes)
                    </button>
                </div>
            </div>
            <div class="mt-3 text-xs text-slate-400 dark:text-slate-500" wire:loading>
                Update running... this can take a few minutes.
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
                        <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $latest->status === 'success' ? 'bg-emerald-500/20 text-emerald-200' : ($latest->status === 'failed' ? 'bg-rose-500/20 text-rose-200' : 'bg-slate-500/20 text-slate-200') }}">
                            {{ strtoupper($latest->status) }}
                        </span>
                    @endif
                </div>

                @if (! $latest)
                    <p class="text-sm text-slate-500 dark:text-slate-400">No update attempts yet.</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-2 text-sm">
                        <div>
                            <div class="text-xs uppercase text-slate-400 dark:text-slate-500">Started</div>
                            <div class="text-slate-700 dark:text-slate-200">{{ $latest->started_at?->format('M j, Y g:i A') ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-slate-400 dark:text-slate-500">Finished</div>
                            <div class="text-slate-700 dark:text-slate-200">{{ $latest->finished_at?->format('M j, Y g:i A') ?? '—' }}</div>
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
                        <pre class="mt-2 max-h-80 overflow-auto rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-950/70 p-4 text-xs text-slate-200">{{ $latest->output_log ?? 'No output captured.' }}</pre>
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
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                {{ $update->started_at?->format('M j, Y g:i a') ?? 'Queued' }}
                            </div>
                            <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $update->status === 'success' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($update->status === 'failed' ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300') }}">
                                {{ $update->status }}
                            </span>
                        </div>
                        <div class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                            {{ $update->from_hash ?? '—' }} → {{ $update->to_hash ?? '—' }}
                        </div>
                        @if ($update->output_log)
                            <details class="mt-3">
                                <summary class="cursor-pointer text-xs text-indigo-600 dark:text-indigo-300">View log</summary>
                                <pre class="mt-2 max-h-80 overflow-auto text-xs text-slate-600 dark:text-slate-300 whitespace-pre-wrap bg-slate-50 dark:bg-slate-950/40 rounded-lg p-3 border border-slate-200/70 dark:border-slate-800">{{ $update->output_log }}</pre>
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
