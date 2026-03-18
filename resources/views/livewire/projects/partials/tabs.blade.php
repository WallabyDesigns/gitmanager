@php
    $tab = $projectsTab ?? (request()->routeIs('projects.queue')
        ? 'queue'
        : (request()->routeIs('projects.create') ? 'create' : 'list'));
@endphp

<div class="flex flex-wrap gap-2 border-b border-slate-200/70 dark:border-slate-800">
    <a href="{{ route('projects.index') }}"
       class="px-3 py-2 text-sm border-b-2 {{ $tab === 'list' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        Projects
    </a>
    <a href="{{ route('projects.create') }}"
       class="px-3 py-2 text-sm border-b-2 {{ $tab === 'create' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        Create Project
    </a>
    <a href="{{ route('projects.queue') }}"
       class="px-3 py-2 text-sm border-b-2 {{ $tab === 'queue' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        Deploy Queue
    </a>
</div>
