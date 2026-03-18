<div class="py-10" wire:init="refreshHealth" wire:poll.60s="refreshHealth">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @include('livewire.projects.partials.tabs')

        <div class="mt-6 space-y-4">
            @forelse ($projects as $project)
                <a href="{{ route('projects.show', $project) }}" class="block rounded-lg border border-slate-200/70 bg-white dark:bg-slate-900 dark:border-slate-800 p-4 transition hover:border-indigo-300 hover:shadow-sm dark:hover:border-indigo-500/60">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $project->name }}</h4>
                                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $project->health_status === 'ok' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($project->health_status === 'fail' ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300') }}">
                                    {{ $project->health_status ?? 'unknown' }}
                                </span>
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

