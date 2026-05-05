<div class="py-10" wire:poll.60s="$refresh">
    
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.projects.partials.tabs', ['showBulkActions' => true])
            
            <div class="mt-6 sm:mt-0 space-y-4">
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-900/40 p-4 text-sm text-slate-200 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 flex-1">
                        <label class="flex flex-col gap-1 text-xs uppercase tracking-wide text-slate-400">
                            {{ __('Search') }}
                            <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('Project name or path') }}" class="w-full rounded-md border border-slate-700 bg-slate-900/70 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none">
                        </label>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="$set('filter','all')" class="px-3 py-2 text-xs rounded-md border {{ $filter === 'all' ? 'border-emerald-400 text-emerald-200' : 'border-slate-700 text-slate-200 hover:text-white hover:border-slate-500' }}">
                            {{ __('All') }} ({{ $counts['all'] ?? 0 }})
                        </button>
                        <button type="button" wire:click="$set('filter','health')" class="px-3 py-2 text-xs rounded-md border {{ $filter === 'health' ? 'border-emerald-400 text-emerald-200' : 'border-slate-700 text-slate-200 hover:text-white hover:border-slate-500' }}">
                            {{ __('Health Issues') }} ({{ $counts['health'] ?? 0 }})
                        </button>
                        <button type="button" wire:click="$set('filter','permissions')" class="px-3 py-2 text-xs rounded-md border {{ $filter === 'permissions' ? 'border-emerald-400 text-emerald-200' : 'border-slate-700 text-slate-200 hover:text-white hover:border-slate-500' }}">
                            {{ __('Permissions Issues') }} ({{ $counts['permissions'] ?? 0 }})
                        </button>
                    </div>
                </div>
                @php
                    $rootProjects = $projectTree['projects'] ?? [];
                    $directoryNodes = array_values($projectTree['directories'] ?? []);
                    $hasProjects = ! empty($rootProjects) || ! empty($directoryNodes);
                @endphp
    
                @if (! $hasProjects)
                    <div class="rounded-lg border border-dashed border-slate-300/70 dark:border-slate-700 p-6 text-sm text-slate-500 dark:text-slate-400">
                        {{ __('No projects yet. Add one above to get started.') }}
                    </div>
                @else
                    @if (! empty($rootProjects))
                        <div class="space-y-4">
                            @foreach ($rootProjects as $project)
                                @include('livewire.projects.partials.project-card', [
                                    'project' => $project,
                                    'queueProjects' => $queueProjects ?? [],
                                    'auditInProcess' => $auditInProcess ?? [],
                                    'buildInProcess' => $buildInProcess ?? [],
                                ])
                            @endforeach
                        </div>
                    @endif
    
                    @if (! empty($directoryNodes))
                        <div class="space-y-3">
                            @foreach ($directoryNodes as $node)
                                @include('livewire.projects.partials.directory-node', [
                                    'node' => $node,
                                    'depth' => 0,
                                    'queueProjects' => $queueProjects ?? [],
                                    'auditInProcess' => $auditInProcess ?? [],
                                    'buildInProcess' => $buildInProcess ?? [],
                                ])
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        </div>

    </div>
</div>
