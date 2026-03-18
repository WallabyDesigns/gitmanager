<div class="flex flex-wrap gap-2 border-b border-slate-200/70 dark:border-slate-800">
    <button type="button"
            wire:click="setTab('list')"
            class="px-3 py-2 text-sm border-b-2 {{ $tab === 'list' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        Current Workflows
    </button>
    <button type="button"
            wire:click="setTab('form')"
            class="px-3 py-2 text-sm border-b-2 {{ $tab === 'form' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        Create Workflow
    </button>
</div>
