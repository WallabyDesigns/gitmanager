<div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-2">
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $project->health_status === 'ok' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($project->health_status === 'fail' ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300') }}">
                        {{ $project->health_status ?? 'unknown' }}
                    </span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Last checked: {{ $project->health_checked_at?->format('M j, Y g:i a') ?? 'Never' }}</span>
                </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="deploy" class="px-3 py-2 text-sm rounded-md bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900">
                            Deploy
                        </button>
                        <button type="button" wire:click="forceDeploy" onclick="return confirm('Force deploy will discard local changes. Continue?') || event.stopImmediatePropagation()" class="px-3 py-2 text-sm rounded-md border border-rose-300 text-rose-600 hover:text-rose-700 dark:border-rose-600/60 dark:text-rose-300">
                            Force Deploy
                        </button>
                    <button type="button" wire:click="rollback" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100">
                        Rollback
                    </button>
                    <button type="button" wire:click="checkUpdates" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100">
                        Check Updates
                    </button>
                    <button type="button" wire:click="checkHealth" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100">
                        Health Check
                    </button>
                        <button type="button" wire:click="updateDependencies" @disabled(! $project->allow_dependency_updates) class="px-3 py-2 text-sm rounded-md border border-indigo-300 text-indigo-600 hover:text-indigo-800 dark:border-indigo-500/50 dark:text-indigo-300 disabled:opacity-40 disabled:cursor-not-allowed">
                            Update Dependencies
                        </button>
                        <a href="{{ route('projects.edit', $project) }}" wire:navigate class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100">
                            Edit
                        </a>
                        <button type="button" wire:click="deleteProject" onclick="return confirm('Delete this project?') || event.stopImmediatePropagation()" class="px-3 py-2 text-sm rounded-md border border-rose-300 text-rose-600 hover:text-rose-700 dark:border-rose-600/60 dark:text-rose-300">
                            Delete
                        </button>
                    </div>
                </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Branch</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $project->default_branch }}</div>
                </div>
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Last Deploy</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {{ $project->last_deployed_at?->format('M j, Y g:i a') ?? 'Never' }}
                    </div>
                    <div class="text-xs text-slate-400 dark:text-slate-500">{{ $project->last_deployed_hash ?? 'No hash yet' }}</div>
                </div>
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Auto Deploy</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {{ $project->auto_deploy ? 'Enabled' : 'Disabled' }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Tests</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {{ $project->run_test_command ? 'Enabled' : 'Disabled' }}
                    </div>
                    <div class="text-xs text-slate-400 dark:text-slate-500">{{ $project->test_command }}</div>
                </div>
            </div>

            @if ($project->last_error_message)
                <div class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
                    {{ $project->last_error_message }}
                </div>
            @endif
        </div>

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Recent Activity</h3>
            <div class="mt-4 space-y-4">
                @forelse ($deployments as $deployment)
                    <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                {{ ucfirst(str_replace('_', ' ', $deployment->action)) }}
                            </div>
                            <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $deployment->status === 'success' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($deployment->status === 'failed' ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300') }}">
                                {{ $deployment->status }}
                            </span>
                        </div>
                        <div class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                            {{ $deployment->started_at?->format('M j, Y g:i a') ?? 'Queued' }}
                        </div>
                        @if ($deployment->from_hash || $deployment->to_hash)
                            <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                {{ $deployment->from_hash ?? 'n/a' }} → {{ $deployment->to_hash ?? 'n/a' }}
                            </div>
                        @endif
                        @if ($deployment->output_log)
                            <details class="mt-3">
                                <summary class="cursor-pointer text-xs text-indigo-600 dark:text-indigo-300">View log</summary>
                                <pre class="mt-2 text-xs text-slate-600 dark:text-slate-300 whitespace-pre-wrap bg-slate-50 dark:bg-slate-950/40 rounded-lg p-3 border border-slate-200/70 dark:border-slate-800">{{ $deployment->output_log }}</pre>
                            </details>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500 dark:text-slate-400">No deployments yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
