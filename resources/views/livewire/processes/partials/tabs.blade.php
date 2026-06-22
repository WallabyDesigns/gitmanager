<div class="border-b border-slate-800 mb-6">
    <nav class="flex gap-1" aria-label="{{ __('Processes navigation') }}">
        <a href="{{ route('processes.index') }}"
           class="px-4 py-2.5 text-sm border-b-2 -mb-px {{ request()->routeIs('processes.index') ? 'border-indigo-500 text-white' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
            {{ __('Active Processes') }}
        </a>
        <a href="{{ route('processes.queue') }}"
           class="px-4 py-2.5 text-sm border-b-2 -mb-px {{ request()->routeIs('processes.queue') ? 'border-indigo-500 text-white' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
            {{ __('Task Queue') }}
        </a>
    </nav>
</div>
