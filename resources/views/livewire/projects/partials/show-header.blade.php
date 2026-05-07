<div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <div class="flex items-center gap-2 text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">
            <a href="{{ route('projects.index') }}" class="hover:text-slate-600 dark:hover:text-slate-300">{{ __('Projects') }}</a>
            <span>/</span>
        </div>
        <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ $project->name }}</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $project->local_path }}</p>
    </div>
    <a href="{{ route('projects.index') }}" class="text-sm flex items-center text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
        <svg width="24px" height="24px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path d="M6 12H18M6 12L11 7M6 12L11 17" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="--darkreader-inline-stroke: var(--darkreader-text-ffffff, #e8e6e3);" data-darkreader-inline-stroke=""></path> </g></svg>
        <p>{{ __('Back to projects') }}</p>
    </a>
</div>
