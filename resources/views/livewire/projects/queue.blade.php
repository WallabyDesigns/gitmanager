<div class="py-10">
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
                <button type="button" wire:click="processNow" class="px-3 py-2 text-xs rounded-md border border-slate-200 text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                    Process Queue
                </button>
                <button type="button" wire:click="purgeDuplicates" class="px-3 py-2 text-xs rounded-md border border-amber-500/50 text-amber-200 hover:text-white hover:bg-amber-500/10">
                    Purge Duplicates
                </button>
                <button type="button" wire:click="clearQueue" class="px-3 py-2 text-xs rounded-md border border-rose-500/60 text-rose-200 hover:text-white hover:bg-rose-500/10">
                    Clear Queue
                </button>
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
                <div class="rounded-lg border border-dashed border-slate-300/70 dark:border-slate-700 p-6 text-sm text-slate-500 dark:text-slate-400">
                    No queued deployments yet.
                </div>
            @endforelse
        </div>
    </div>
</div>
