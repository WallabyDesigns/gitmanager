@php
    $tab = $projectsTab ?? (request()->routeIs('projects.queue')
        ? 'queue'
        : (request()->routeIs('projects.scheduler') ? 'scheduler' : (request()->routeIs('projects.create') ? 'create' : 'list')));
    $queueEnabled = config('gitmanager.deploy_queue.enabled', true);
    $scheduler = app(\App\Services\SchedulerService::class);
    $schedulerHealthy = $scheduler->isHealthy();
    $lastHeartbeat = $scheduler->lastHeartbeat();
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
    <a href="{{ route('projects.scheduler') }}"
       class="px-3 py-2 text-sm border-b-2 {{ $tab === 'scheduler' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        Scheduler Settings
    </a>
</div>

@if ($queueEnabled && ! $schedulerHealthy)
    <div class="rounded-xl border border-amber-300/60 p-4 text-sm text-amber-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="text-xs uppercase tracking-wide text-amber-300">Scheduler not detected.</div>
            <div class="text-sm text-amber-100">
                Queued deployments will not run until the scheduler is running.
                @if ($lastHeartbeat)
                    Last heartbeat: {{ $lastHeartbeat->toDayDateTimeString() }}.
                @else
                    No heartbeat recorded yet.
                @endif
            </div>
        </div>
        <a href="{{ route('projects.scheduler') }}" class="inline-flex items-center justify-center rounded-md border border-amber-300/60 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:border-white hover:text-white">
            Open Scheduler Settings
        </a>
    </div>
@endif
