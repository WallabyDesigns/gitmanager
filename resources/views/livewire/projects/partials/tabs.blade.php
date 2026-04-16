@php
    $showBulkActions = $showBulkActions ?? false;
    $tab = $projectsTab ?? (request()->routeIs('projects.queue')
        ? 'queue'
        : (request()->routeIs('projects.create') ? 'create' : 'list'));
    $queueEnabled = config('gitmanager.deploy_queue.enabled', true);
    $scheduler = app(\App\Services\SchedulerService::class);
    $schedulerGraceSeconds = max(600, (int) config('gitmanager.scheduler.stale_seconds', 600));
    $schedulerHealthy = $scheduler->isHealthy($schedulerGraceSeconds);
    $lastHeartbeat = $scheduler->lastHeartbeat();
    $isAdmin = auth()->user()?->isAdmin() ?? false;
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/70 dark:border-slate-800">
    <div class="flex flex-wrap gap-2">
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
            Task Queue
        </a>
    </div>
    @if ($showBulkActions && $tab === 'list')
        <div class="flex flex-wrap gap-2 pb-2 sm:pb-0">
            <button type="button" wire:click="checkAllHealth" wire:loading.attr="disabled" class="px-3 py-1.5 text-xs rounded-md border border-emerald-300 text-emerald-200 hover:text-white hover:border-emerald-200 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                <x-loading-spinner target="checkAllHealth" size="w-3 h-3" class="mr-1" />
                Check Health
            </button>
            <button type="button" wire:click="checkAllUpdates" wire:loading.attr="disabled" class="px-3 py-1.5 text-xs rounded-md border border-indigo-300 text-indigo-200 hover:text-white hover:border-indigo-200 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                <x-loading-spinner target="checkAllUpdates" size="w-3 h-3" class="mr-1" />
                Check Updates
            </button>
            <button type="button" wire:click="auditAllProjects" wire:loading.attr="disabled" class="px-3 py-1.5 text-xs rounded-md border border-emerald-300 text-emerald-200 hover:text-white hover:border-emerald-200 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                <x-loading-spinner target="auditAllProjects" size="w-3 h-3" class="mr-1" />
                Audit Projects
            </button>
        </div>
    @endif
</div>

@if ($queueEnabled && ! $schedulerHealthy)
    <div class="rounded-xl border border-amber-300/60 p-4 text-sm text-amber-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="text-xs uppercase tracking-wide text-amber-300">Scheduler not detected.</div>
            <div class="text-sm text-amber-100">
                Queued tasks will not run until the scheduler is running.
                @if ($lastHeartbeat)
                    Last heartbeat: {{ \App\Support\DateFormatter::forUser($lastHeartbeat, 'M j, Y g:i a') }}.
                @else
                    No heartbeat recorded yet.
                @endif
            </div>
        </div>
        @if ($isAdmin)
            <a href="{{ route('system.settings') }}#scheduler-settings" class="inline-flex items-center justify-center rounded-md border border-amber-300/60 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:border-white hover:text-white">
                Open Scheduler Settings
            </a>
        @else
            <div class="text-xs text-amber-200">
                Ask an administrator to configure scheduler settings.
            </div>
        @endif
    </div>
@endif
