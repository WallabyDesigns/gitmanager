<div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @include('livewire.projects.partials.tabs')

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Queued Deployments</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Only queued items can be cancelled or reordered.</p>
                </div>
            </div>
        </div>

        <div class="space-y-3">
            @forelse ($items as $item)
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $item->project?->name ?? 'Unknown project' }}</h4>
                            <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $item->status === 'queued' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : ($item->status === 'running' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' : ($item->status === 'completed' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300')) }}">
                                {{ $item->status }}
                            </span>
                        </div>
                        <div class="text-xs text-slate-400 dark:text-slate-500">
                            Action: {{ $item->action }} • Position: {{ $item->position }}
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
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-500">Queue locked</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500 dark:text-slate-400">No queued deployments yet.</p>
            @endforelse
        </div>
    </div>
</div>
