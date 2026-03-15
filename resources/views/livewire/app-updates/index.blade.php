<div class="py-10">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Run Update</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">This will pull from the `Costigan-Stephen/gitmanager` repo and apply updates locally.</p>
                </div>
                <button type="button" wire:click="runUpdate" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500">
                    Update App
                </button>
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
    </div>
</div>
