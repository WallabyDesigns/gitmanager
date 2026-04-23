@php
    use App\Services\NavigationStateService;

    $showBulkActions = $showBulkActions ?? false;
    $tab = $projectsTab ?? (request()->routeIs('projects.queue')
        ? 'queue'
        : (request()->routeIs('projects.create')
            ? 'create'
            : (request()->routeIs('projects.action-center') ? 'action-center' : 'list')));
    $projectNavState = app(NavigationStateService::class)->projectsSidebarState(auth()->user());
    $isAdmin = (bool) ($projectNavState['isAdmin'] ?? false);
    $isEnterprise = (bool) ($projectNavState['isEnterprise'] ?? false);
    $isFtpRoute = request()->routeIs('ftp-accounts.*');
    $queueCount = (int) ($projectNavState['queueCount'] ?? 0);
    $actionCenterCount = (int) ($projectNavState['actionCenterCount'] ?? 0);

    $currentTabLabel = match ($tab) {
        'create' => 'Create Project',
        'queue' => 'Task Queue',
        'action-center' => 'Action Center',
        default => $isFtpRoute ? 'FTP/SSH Access' : 'Projects',
    };
@endphp

<div x-data="{ open: false }" class="contents" @keydown.escape.window="open = false" x-on:livewire:navigating.window="open = false" x-effect="document.body.classList.toggle('overflow-hidden', open)">

    {{-- Mobile trigger (hidden on lg+) --}}
    <div class="lg:hidden">
        <button
            type="button"
            @click="open = true"
            class="flex w-full items-center justify-between gap-2 rounded-xl border border-slate-700 bg-slate-950/90 px-4 py-3 text-sm text-slate-200 hover:border-slate-600 hover:text-white transition"
        >
            <span class="flex items-center gap-2">
                <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                <span class="text-xs uppercase tracking-[0.16em] text-slate-400">Projects</span>
                <span class="font-medium text-white">{{ $currentTabLabel }}</span>
                @if ($tab === 'queue' && $queueCount > 0)
                    <span class="inline-flex items-center justify-center rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">{{ $queueCount }}</span>
                @elseif ($tab === 'action-center' && $actionCenterCount > 0)
                    <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $actionCenterCount }}</span>
                @endif
            </span>
            <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </button>
    </div>

    {{-- Desktop aside (hidden on mobile) --}}
    <aside class="hidden lg:block space-y-4">
        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-slate-950/90 p-4 text-slate-200">
            <div>
                <div class="text-xs uppercase tracking-[0.16em] text-slate-400">Projects</div>
                <div class="mt-1 text-lg font-semibold text-white">Workspace</div>
                <div class="text-xs text-slate-400">Deploys, action items, queue operations, and remote access.</div>
            </div>

            <nav class="mt-4 space-y-1.5" aria-label="Projects navigation">
                <a
                    href="{{ route('projects.create') }}"
                    wire:navigate.hover
                    class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'create' ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Create Project
                </a>
                <a
                    href="{{ route('projects.index') }}"
                    wire:navigate.hover
                    class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'list' && ! $isFtpRoute ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" /></svg>
                    Projects
                </a>
                <a
                    href="{{ route('projects.queue') }}"
                    wire:navigate.hover
                    class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'queue' ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" /></svg>
                    <span class="inline-flex items-center gap-2">
                        Task Queue
                        @if ($queueCount > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">{{ $queueCount }}</span>
                        @endif
                    </span>
                </a>
                <a
                    href="{{ route('projects.action-center') }}"
                    wire:navigate.hover
                    class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'action-center' ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 3.94c.09-.542.56-.94 1.11-.94h1.1c.55 0 1.02.398 1.11.94l.154.925c.062.374.312.686.643.87.128.071.255.145.378.223.324.205.72.266 1.075.133l.88-.33a1.125 1.125 0 011.37.49l.55.952a1.125 1.125 0 01-.26 1.43l-.726.598c-.292.24-.437.613-.43.991.003.149.003.298 0 .447-.007.378.138.75.43.99l.726.599c.424.35.534.954.26 1.43l-.55.952a1.125 1.125 0 01-1.37.49l-.88-.33c-.355-.133-.751-.072-1.075.133-.123.078-.25.152-.378.223-.331.184-.581.496-.643.87l-.154.925c-.09.542-.56.94-1.11.94h-1.1c-.55 0-1.02-.398-1.11-.94l-.154-.925a1.125 1.125 0 00-.643-.87 6.343 6.343 0 01-.378-.223c-.324-.205-.72-.266-1.075-.133l-.88.33a1.125 1.125 0 01-1.37-.49l-.55-.952a1.125 1.125 0 01.26-1.43l.726-.598c.292-.24.437-.613.43-.991a9.079 9.079 0 010-.447c.007-.378-.138-.75-.43-.99l-.726-.599a1.125 1.125 0 01-.26-1.43l.55-.952a1.125 1.125 0 011.37-.49l.88.33c.355.133.751.072 1.075-.133.123-.078.25-.152.378-.223.331-.184.581-.496.643-.87l.154-.925z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 12a2.25 2.25 0 104.5 0 2.25 2.25 0 00-4.5 0z" /></svg>
                    <span class="inline-flex items-center gap-2">
                        Action Center
                        @if ($actionCenterCount > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $actionCenterCount }}</span>
                        @endif
                    </span>
                </a>
                @if ($isAdmin)
                        <a
                            href="{{ route('ftp-accounts.index') }}"
                            wire:navigate.hover
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

    {{-- Mobile drawer (teleported to body) --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            class="lg:hidden fixed inset-0 z-[1100]"
            aria-hidden="true"
        >
            <div
                class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm"
                @click="open = false"
                x-transition:enter="transition-opacity ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
            ></div>
            <div
                class="absolute inset-y-0 left-0 w-[22rem] max-w-[90vw] bg-slate-950 border-r border-slate-800 flex flex-col overflow-y-auto"
                x-transition:enter="transition-transform ease-out duration-250"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform ease-in duration-200"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
            >
                <div class="flex items-start justify-between px-4 py-4 border-b border-slate-800">
                    <div>
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">Projects</div>
                        <div class="mt-1 text-lg font-semibold text-white">Workspace</div>
                        <div class="text-xs text-slate-400">Deploys, action items, queue operations, and remote access.</div>
                    </div>
                    <button type="button" @click="open = false" class="mt-0.5 rounded-lg p-1.5 text-slate-400 hover:text-white hover:bg-slate-800 transition shrink-0">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <nav class="p-4 space-y-1.5" aria-label="Projects navigation">
                    <a
                        href="{{ route('projects.create') }}"
                        wire:navigate.hover
                        @click="open = false"
                        class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'create' ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                    >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Create Project
                    </a>
                    <a
                        href="{{ route('projects.index') }}"
                        wire:navigate.hover
                        @click="open = false"
                        class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'list' && ! $isFtpRoute ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                    >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" /></svg>
                        Projects
                    </a>
                    <a
                        href="{{ route('projects.queue') }}"
                        wire:navigate.hover
                        @click="open = false"
                        class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'queue' ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                    >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" /></svg>
                        <span class="inline-flex items-center gap-2">
                            Task Queue
                            @if ($queueCount > 0)
                                <span class="inline-flex items-center justify-center rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">{{ $queueCount }}</span>
                            @endif
                        </span>
                    </a>
                    <a
                        href="{{ route('projects.action-center') }}"
                        wire:navigate.hover
                        @click="open = false"
                        class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $tab === 'action-center' ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                    >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 3.94c.09-.542.56-.94 1.11-.94h1.1c.55 0 1.02.398 1.11.94l.154.925c.062.374.312.686.643.87.128.071.255.145.378.223.324.205.72.266 1.075.133l.88-.33a1.125 1.125 0 011.37.49l.55.952a1.125 1.125 0 01-.26 1.43l-.726.598c-.292.24-.437.613-.43.991.003.149.003.298 0 .447-.007.378.138.75.43.99l.726.599c.424.35.534.954.26 1.43l-.55.952a1.125 1.125 0 01-1.37.49l-.88-.33c-.355-.133-.751-.072-1.075.133-.123.078-.25.152-.378.223-.331.184-.581.496-.643.87l-.154.925c-.09.542-.56.94-1.11.94h-1.1c-.55 0-1.02-.398-1.11-.94l-.154-.925a1.125 1.125 0 00-.643-.87 6.343 6.343 0 01-.378-.223c-.324-.205-.72-.266-1.075-.133l-.88.33a1.125 1.125 0 01-1.37-.49l-.55-.952a1.125 1.125 0 01.26-1.43l.726-.598c.292-.24.437-.613.43-.991a9.079 9.079 0 010-.447c.007-.378-.138-.75-.43-.99l-.726-.599a1.125 1.125 0 01-.26-1.43l.55-.952a1.125 1.125 0 011.37-.49l.88.33c.355.133.751.072 1.075-.133.123-.078.25-.152.378-.223.331-.184.581-.496.643-.87l.154-.925z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 12a2.25 2.25 0 104.5 0 2.25 2.25 0 00-4.5 0z" /></svg>
                        <span class="inline-flex items-center gap-2">
                            Action Center
                            @if ($actionCenterCount > 0)
                                <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $actionCenterCount }}</span>
                            @endif
                        </span>
                    </a>
                    @if ($isAdmin)
                        <a
                            href="{{ route('ftp-accounts.index') }}"
                            wire:navigate.hover
                            @click="open = false"
                            class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ request()->routeIs('ftp-accounts.*') ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                        >
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                            FTP/SSH Access
                        </a>
                    @endif
                </nav>
            </div>
        </div>
    </template>
</div>
