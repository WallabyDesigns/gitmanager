<div class="py-10" wire:init="refreshHealth" wire:poll.60s="refreshHealth">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @include('livewire.projects.partials.tabs')

        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-900/40 p-4 text-sm text-slate-200 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 flex-1">
                <label class="flex flex-col gap-1 text-xs uppercase tracking-wide text-slate-400">
                    Search
                    <input type="text" wire:model.debounce.300ms="search" placeholder="Project name or path" class="w-full rounded-md border border-slate-700 bg-slate-900/70 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none">
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="$set('filter','all')" class="px-3 py-2 text-xs rounded-md border {{ $filter === 'all' ? 'border-emerald-400 text-emerald-200' : 'border-slate-700 text-slate-200 hover:text-white hover:border-slate-500' }}">
                    All ({{ $counts['all'] ?? 0 }})
                </button>
                <button type="button" wire:click="$set('filter','health')" class="px-3 py-2 text-xs rounded-md border {{ $filter === 'health' ? 'border-emerald-400 text-emerald-200' : 'border-slate-700 text-slate-200 hover:text-white hover:border-slate-500' }}">
                    Health Issues ({{ $counts['health'] ?? 0 }})
                </button>
                <button type="button" wire:click="$set('filter','permissions')" class="px-3 py-2 text-xs rounded-md border {{ $filter === 'permissions' ? 'border-emerald-400 text-emerald-200' : 'border-slate-700 text-slate-200 hover:text-white hover:border-slate-500' }}">
                    Permissions Issues ({{ $counts['permissions'] ?? 0 }})
                </button>
            </div>
        </div>

        <div class="mt-6 space-y-4">
            @forelse ($projects as $project)
                <a href="{{ route('projects.show', $project) }}" class="block rounded-lg border border-slate-200/70 bg-white dark:bg-slate-900 dark:border-slate-800 p-4 transition hover:border-indigo-300 hover:shadow-sm dark:hover:border-indigo-500/60">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $project->name }}</h4>
                            @php
                                $healthStatus = $project->health_status ?? 'na';
                                $healthLabel = $healthStatus === 'ok' ? 'Health: OK' : 'Health: N/A';
                                $healthClass = $healthStatus === 'ok'
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                    : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300';
                                $ftpNeedsTest = $project->ftp_enabled
                                    && $project->ftpAccount
                                    && $project->ftpAccount->ftpNeedsTest();
                                $sshNeedsTest = $project->ssh_enabled
                                    && $project->ftpAccount
                                    && $project->ftpAccount->sshNeedsTest();
                                $permissionsIssue = $project->permissionsEnforced()
                                    && $project->permissions_locked;
                                $ftpIssue = $project->ftp_enabled
                                    && $project->ftpAccount
                                    && in_array($project->ftpAccount->ftp_test_status, ['error', 'warning'], true)
                                    && ! $ftpNeedsTest;
                                $sshIssue = $project->ssh_enabled
                                    && $project->ftpAccount
                                    && in_array($project->ftpAccount->ssh_test_status, ['error', 'warning'], true)
                                    && ! $sshNeedsTest;
                            @endphp
                            <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $healthClass }}">
                                {{ $healthLabel }}
                            </span>
                            @if ($permissionsIssue)
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                                    Permissions
                                </span>
                            @endif
                            @if ($project->updates_available)
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                                    Updates Available
                                </span>
                            @endif
                            @if ($project->ftp_enabled)
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
                                    FTPS
                                </span>
                            @endif
                            @if ($ftpNeedsTest)
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    FTP Needs Test
                                </span>
                            @endif
                            @if ($sshNeedsTest)
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    SSH Needs Test
                                </span>
                            @endif
                            @if ($ftpIssue)
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                                    FTP Issue
                                </span>
                            @endif
                            @if ($sshIssue)
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                                    SSH Issue
                                </span>
                            @endif
                            @if (in_array($project->id, $queueProjects ?? [], true))
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                    In Queue
                                </span>
                            @endif
                            @if (in_array($project->id, $buildInProcess ?? [], true))
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                                    Build in process
                                </span>
                            @endif
                            </div>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $project->local_path }}</p>
                            <div class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                                @php($lastDeploy = $project->last_deployed_at ?? ($project->last_successful_deploy_at ?? null))
                                Last deployed: {{ $lastDeploy?->format('M j, Y g:i a') ?? 'Never' }}
                            </div>
                        </div>
                        <div class="text-xs text-slate-400 dark:text-slate-500">
                            <svg width="32px" height="32px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path d="M6 12H18M18 12L13 7M18 12L13 17" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="--darkreader-inline-stroke: var(--darkreader-text-ffffff, #e8e6e3);" data-darkreader-inline-stroke=""></path> </g></svg>
                        </div>
                    </div>
                </a>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300/70 dark:border-slate-700 p-6 text-sm text-slate-500 dark:text-slate-400">
                    No projects yet. Add one above to get started.
                </div>
            @endforelse
        </div>
    </div>
</div>
