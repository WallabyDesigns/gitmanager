<div class="py-10" wire:poll.5s="$refresh">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @include('livewire.projects.partials.tabs')

        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/60 p-4 text-sm text-slate-600 dark:text-slate-300 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="font-semibold text-slate-900 dark:text-slate-100">Queue Runner</div>
                <div>
                    Queued deployments are processed by the scheduler (<code>php artisan schedule:work</code>) or a cron task.
                    If the scheduler isn’t running, use “Process Queue” to advance items.
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="processNow" class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white inline-flex items-center">
                    <x-loading-spinner target="processNow" />
                    Process Queue
                </button>
                <button type="button" wire:click="purgeDuplicates" class="px-3 py-2 text-xs rounded-md border border-amber-500/50 text-amber-200 hover:text-white hover:bg-amber-500/10 inline-flex items-center">
                    <x-loading-spinner target="purgeDuplicates" />
                    Purge Duplicates
                </button>
                <button type="button" wire:click="clearQueue" class="px-3 py-2 text-xs rounded-md border border-rose-500/60 text-rose-200 hover:text-white hover:bg-rose-500/10 inline-flex items-center">
                    <x-loading-spinner target="clearQueue" />
                    Clear Queue
                </button>
            </div>
        </div>

        @php
            $queueTabs = [
                'queued' => 'Queued',
                'running' => 'Running',
                'failed' => 'Failed',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                'all' => 'All',
            ];
        @endphp

        <div class="flex flex-wrap gap-2 border-b border-slate-200/70 dark:border-slate-800">
            @foreach ($queueTabs as $value => $label)
                <button type="button"
                        wire:key="queue-status-{{ $value }}"
                        wire:click="setStatusFilter('{{ $value }}')"
                        class="px-3 py-2 text-sm border-b-2 {{ $statusFilter === $value ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-900/40 p-4 text-sm text-slate-200 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 flex-1">
                <label class="flex flex-col gap-1 text-xs uppercase tracking-wide text-slate-400">
                    Search
                    <input type="text" wire:model.debounce.400ms="search" placeholder="Project name or action" class="w-full rounded-md border border-slate-700 bg-slate-900/70 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none">
                </label>
                <label class="flex flex-col gap-1 text-xs uppercase tracking-wide text-slate-400">
                    Status
                    <select wire:model="statusFilter" class="w-full rounded-md border border-slate-700 bg-slate-900/70 px-3 py-2 text-sm text-slate-100 focus:border-emerald-400 focus:outline-none">
                        <option value="all">All</option>
                        <option value="queued">Queued</option>
                        <option value="running">Running</option>
                        <option value="completed">Completed</option>
                        <option value="failed">Failed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs uppercase tracking-wide text-slate-400">
                    Action
                    <select wire:model="actionFilter" class="w-full rounded-md border border-slate-700 bg-slate-900/70 px-3 py-2 text-sm text-slate-100 focus:border-emerald-400 focus:outline-none">
                        <option value="all">All</option>
                        <option value="deploy">Deploy</option>
                        <option value="force_deploy">Force Deploy</option>
                        <option value="rollback">Rollback</option>
                        <option value="dependency_update">Dependency Update</option>
                        <option value="composer_install">Composer Install</option>
                        <option value="composer_update">Composer Update</option>
                        <option value="composer_audit">Composer Audit</option>
                        <option value="npm_install">Npm Install</option>
                        <option value="npm_update">Npm Update</option>
                        <option value="npm_audit_fix">Npm Audit Fix</option>
                        <option value="npm_audit_fix_force">Npm Audit Fix (Force)</option>
                        <option value="app_clear_cache">App Clear Cache</option>
                        <option value="laravel_migrate">Laravel Migrate</option>
                        <option value="preview_build">Preview Build</option>
                        <option value="custom_command">Custom Command</option>
                    </select>
                </label>
            </div>
            <div class="flex justify-end">
                <button type="button" wire:click="clearFilters" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white hover:border-slate-500">
                    Clear Filters
                </button>
            </div>
        </div>

        <div class="space-y-3">
            @forelse ($items as $item)
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4 flex flex-col gap-3" wire:key="queue-item-{{ $item->id }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $item->project?->name ?? 'Unknown project' }}</h4>
                            <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $item->status === 'queued' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : ($item->status === 'running' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' : ($item->status === 'completed' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300')) }}">
                                {{ $item->status }}
                            </span>
                            @php
                                $permissionsIssue = $item->project
                                    && $item->project->permissionsEnforced()
                                    && $item->project->permissions_locked;
                            @endphp
                            @if ($permissionsIssue)
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                                    Permissions
                                </span>
                            @endif
                            @if ($item->project_id && $runningDeployments->has($item->project_id))
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                                    Build in process
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-slate-400 dark:text-slate-500">
                            @php
                                $timestampLabel = match ($item->status) {
                                    'queued' => 'Queued',
                                    'running' => 'Started',
                                    'completed', 'failed', 'cancelled' => 'Finished',
                                    default => 'Updated',
                                };
                                $timestampValue = match ($item->status) {
                                    'queued' => $item->created_at,
                                    'running' => $item->started_at ?? $item->created_at,
                                    'completed', 'failed', 'cancelled' => $item->finished_at ?? $item->started_at ?? $item->created_at,
                                    default => $item->updated_at ?? $item->created_at,
                                };
                            @endphp
                            Action: {{ $item->action }} • Position: {{ $item->position }}
                            @if ($timestampValue)
                                • {{ $timestampLabel }}: {{ $timestampValue->format('M j, Y g:i a') }}
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($item->status === 'queued')
                            <button type="button" wire:click="moveUp({{ $item->id }})" class="px-3 py-1.5 text-xs rounded-md border border-slate-200 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-white">
                                Move Up
                            </button>
                            <button type="button" wire:click="moveDown({{ $item->id }})" class="px-3 py-1.5 text-xs rounded-md border border-slate-200 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-white">
                                Move Down
                            </button>
                            <button type="button" wire:click="cancel({{ $item->id }})" class="px-3 py-1.5 text-xs rounded-md border border-rose-500/60 text-rose-200 hover:text-white hover:bg-rose-500/10">
                                Cancel
                            </button>
                        @elseif ($item->status === 'running')
                            @php($staleCutoff = now()->subSeconds($staleSeconds ?? 900))
                            <button type="button"
                                    wire:click="forceCancel({{ $item->id }})"
                                    onclick="return confirm('Force cancel this running item? This will release the queue lock but may not stop the underlying process.') || event.stopImmediatePropagation()"
                                    class="px-3 py-1.5 text-xs rounded-md border border-rose-500/60 text-rose-200 hover:text-white hover:bg-rose-500/10">
                                Force Cancel
                            </button>
                            @if ($item->started_at && $item->started_at->lt($staleCutoff))
                                <span class="text-xs text-amber-200">Stale</span>
                            @endif
                        @endif
                    </div>
                    </div>
                    @php($log = $item->deployment?->output_log ?? ($runningDeployments[$item->project_id]->output_log ?? null))
                    @if ($item->status === 'running' || $log)
                        <details
                            class="mt-2"
                            x-data="{
                                open: {{ $item->status === 'running' ? 'true' : 'false' }},
                                key: 'gwm-queue-log-{{ $item->id }}'
                            }"
                            x-init="
                                const stored = localStorage.getItem(key);
                                open = stored !== null ? stored === 'true' : open;
                                $el.open = open;
                            "
                            x-bind:open="open"
                            @toggle="open = $el.open; localStorage.setItem(key, open)"
                        >
                            <summary class="cursor-pointer text-xs text-indigo-600 dark:text-indigo-300">
                                {{ $item->status === 'running' ? 'Live deployment log' : 'Deployment log' }}
                            </summary>
                            @include('livewire.projects.partials.grouped-log', [
                                'log' => $log,
                                'maxHeight' => 'max-h-[calc(100vh-18rem)]',
                                'autoScroll' => true,
                                'reverse' => true,
                                'placeholder' => 'No output yet. Refreshing...',
                            ])
                        </details>
                    @endif
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300/70 dark:border-slate-700 p-6 text-sm text-slate-500 dark:text-slate-400">
                    No queued deployments yet.
                </div>
            @endforelse
        </div>

        @if ($items->hasPages())
            <div class="pt-4">
                {{ $items->links() }}
            </div>
        @endif
    </div>
</div>
