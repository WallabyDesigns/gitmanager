@php
    $showBulkActions = $showBulkActions ?? false;
    $showSchedulerNotice = $showSchedulerNotice ?? true;
    $tab = $projectsTab ?? (request()->routeIs('projects.queue')
        ? 'queue'
        : (request()->routeIs('projects.create')
            ? 'create'
            : (request()->routeIs('projects.scheduler') ? 'scheduler' : 'list')));
    $queueEnabled = config('gitmanager.deploy_queue.enabled', true);
    $scheduler = app(\App\Services\SchedulerService::class);
    $schedulerGraceSeconds = max(600, (int) config('gitmanager.scheduler.stale_seconds', 600));
    $schedulerHealthy = $scheduler->isHealthy($schedulerGraceSeconds);
    $lastHeartbeat = $scheduler->lastHeartbeat();
    $isAdmin = auth()->user()?->isAdmin() ?? false;
    $isEnterprise = app(\App\Services\EditionService::class)->current() === \App\Services\EditionService::ENTERPRISE;
    $isFtpRoute = request()->routeIs('ftp-accounts.*');
@endphp

<aside class="space-y-4">
    <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-slate-950/90 p-4 text-slate-200">
        <div>
            <div class="text-xs uppercase tracking-[0.16em] text-slate-400">Projects</div>
            <div class="mt-1 text-lg font-semibold text-white">Workspace</div>
            <div class="text-xs text-slate-400">Deploys, queue operations, remote access, and scheduler controls.</div>
        </div>

        <nav class="mt-4 space-y-1.5">
            <a
                href="{{ route('projects.create') }}"
                class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'create' ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
            >
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Create Project
            </a>
            <a
                href="{{ route('projects.index') }}"
                class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'list' && ! $isFtpRoute ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
            >
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" /></svg>
                Projects
            </a>
            <a
                href="{{ route('projects.queue') }}"
                class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'queue' ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
            >
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" /></svg>
                Task Queue
            </a>
            <a
                href="{{ route('projects.scheduler') }}"
                class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'scheduler' ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
            >
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Scheduler
            </a>
            @if ($isAdmin)
                <a
                    href="{{ route('ftp-accounts.index') }}"
                    class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ request()->routeIs('ftp-accounts.*') ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                    FTP/SSH Access
                </a>
            @endif
        </nav>

        @if ($showBulkActions && $tab === 'list' && ! $isFtpRoute)
            <div class="mt-4 border-t border-slate-800 pt-4 space-y-2">
                <div class="text-xs uppercase tracking-[0.12em] text-slate-500">Bulk Actions</div>
                <button type="button" wire:click="checkAllHealth" wire:loading.attr="disabled" class="w-full px-3 py-2 text-xs rounded-md border border-emerald-400/50 text-emerald-200 hover:text-white hover:border-emerald-300 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed">
                    <x-loading-spinner target="checkAllHealth" size="w-3 h-3" class="mr-1" />
                    Check Health
                </button>
                <button type="button" wire:click="checkAllUpdates" wire:loading.attr="disabled" class="w-full px-3 py-2 text-xs rounded-md border border-indigo-400/50 text-indigo-200 hover:text-white hover:border-indigo-300 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed">
                    <x-loading-spinner target="checkAllUpdates" size="w-3 h-3" class="mr-1" />
                    Check Updates
                </button>
                @if ($isEnterprise)
                    <button type="button" wire:click="auditAllProjects" wire:loading.attr="disabled" class="w-full px-3 py-2 text-xs rounded-md border border-emerald-400/50 text-emerald-200 hover:text-white hover:border-emerald-300 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed">
                        <x-loading-spinner target="auditAllProjects" size="w-3 h-3" class="mr-1" />
                        Audit Projects
                    </button>
                @else
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Automatic Project & Container Audits' } }));" class="w-full px-3 py-2 text-xs rounded-md border border-amber-400/50 text-amber-200 hover:text-amber-100 hover:border-amber-300 inline-flex items-center justify-center">
                        <svg class="mr-1.5 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                        Audit Projects
                    </button>
                @endif
            </div>
        @endif
    </div>
</aside>
