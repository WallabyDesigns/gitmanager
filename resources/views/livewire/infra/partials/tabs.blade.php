<div class="flex flex-wrap gap-1 border-b border-slate-200/70 dark:border-slate-800">
    @if (\Illuminate\Support\Facades\Route::has('infra.containers'))
        <a href="{{ route('infra.containers') }}"
           class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition
               {{ request()->routeIs('infra.containers*') && ! request()->routeIs('infra.kubernetes*')
                   ? 'border-indigo-500 text-indigo-700 dark:text-indigo-300'
                   : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600' }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
            </svg>
            Containers
        </a>
    @endif
    @if (\Illuminate\Support\Facades\Route::has('infra.kubernetes'))
        <a href="{{ route('infra.kubernetes') }}"
           class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition
               {{ request()->routeIs('infra.kubernetes*')
                   ? 'border-indigo-500 text-indigo-700 dark:text-indigo-300'
                   : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600' }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z" />
            </svg>
            Kubernetes
        </a>
    @endif
</div>
