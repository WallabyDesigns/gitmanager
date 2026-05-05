<div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ __('Edit :project', ['project' => $project->name]) }}</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Adjust deploy settings and health checks.') }}</p>
    </div>
    <a href="{{ route('projects.index') }}" class="text-sm flex items-center text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        {{ __('Back to projects') }}
    </a>
</div>
