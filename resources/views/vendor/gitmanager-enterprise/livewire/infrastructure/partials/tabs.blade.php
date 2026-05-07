@php
    $component = $this ?? null;
    $isContainersComponent = is_object($component) && is_a($component, \GitManagerEnterprise\Livewire\Infrastructure\Containers::class);
    $isKubernetesComponent = is_object($component) && is_a($component, \GitManagerEnterprise\Livewire\Infrastructure\Kubernetes::class);
    $isContainersActive = ($infraTab ?? null) === 'docker'
        || $isContainersComponent
        || (request()->routeIs('infra.containers*') && ! request()->routeIs('infra.kubernetes*'));
    $isKubernetesActive = ($infraTab ?? null) === 'kubernetes'
        || $isKubernetesComponent
        || request()->routeIs('infra.kubernetes*');
    $currentInfraLabel = $isKubernetesActive ? __('Kubernetes') : __('Docker');
    $drawerActiveSection = $activeSection ?? null;
    $drawerIsEnterprise = (bool) ($isEnterprise ?? false);
    $drawerNavItem = 'group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition';
    $drawerActiveNav = 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100';
    $drawerIdleNav = 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white';
    $drawerLockIcon = '<svg class="h-3.5 w-3.5 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd" /></svg>';

    $containerDrawerSections = [
        ['label' => __('Dashboard'), 'section' => 'dashboard', 'activeSection' => 'overview', 'icon' => 'grid'],
        ['label' => __('Docker Containers'), 'section' => 'containers', 'icon' => 'cube'],
        ['label' => __('Images'), 'section' => 'images', 'icon' => 'layers'],
        ['label' => __('Volumes'), 'section' => 'volumes', 'icon' => 'database'],
        ['label' => __('Networks'), 'section' => 'networks', 'icon' => 'share'],
        ['label' => __('Swarm'), 'section' => 'swarm', 'enterpriseFeature' => __('Docker Swarm'), 'icon' => 'screen'],
        ['divider' => true],
        ['label' => __('Servers'), 'section' => 'servers', 'activeSection' => 'nodes', 'icon' => 'server'],
        ['label' => __('Stacks'), 'section' => 'stacks', 'icon' => 'layers'],
        ['label' => __('Deployments'), 'section' => 'deployments', 'icon' => 'rocket'],
        ['label' => __('Builds'), 'section' => 'builds', 'icon' => 'wrench'],
        ['label' => __('Repos'), 'section' => 'repos', 'icon' => 'code'],
        ['label' => __('Procedures & Actions'), 'section' => 'procedures', 'icon' => 'bolt'],
        ['label' => __('Templates'), 'section' => 'templates', 'icon' => 'document'],
        ['label' => __('Syncs'), 'section' => 'syncs', 'icon' => 'sync'],
        ['label' => __('Databases'), 'section' => 'databases', 'icon' => 'database'],
        ['label' => __('Audits'), 'section' => 'audits', 'showLock' => ! $drawerIsEnterprise, 'icon' => 'clipboard'],
        ['divider' => true],
        ['label' => __('Alerts'), 'section' => 'alerts', 'icon' => 'bell'],
        ['label' => __('Settings'), 'section' => 'settings', 'icon' => 'cog'],
    ];

    $kubernetesSectionAccess = \GitManagerEnterprise\Livewire\Infrastructure\Kubernetes::SECTION_ACCESS;
    $kubernetesSectionWorkloads = \GitManagerEnterprise\Livewire\Infrastructure\Kubernetes::SECTION_WORKLOADS;
    $kubernetesSectionControls = \GitManagerEnterprise\Livewire\Infrastructure\Kubernetes::SECTION_CONTROLS;
    $kubernetesSectionEvents = \GitManagerEnterprise\Livewire\Infrastructure\Kubernetes::SECTION_EVENTS;
    $kubernetesSectionAudit = \GitManagerEnterprise\Livewire\Infrastructure\Kubernetes::SECTION_AUDIT;
    $kubernetesDrawerSections = $drawerIsEnterprise
        ? [
            ['label' => __('Access'), 'section' => $kubernetesSectionAccess, 'icon' => 'bars'],
            ['label' => __('Workloads'), 'section' => $kubernetesSectionWorkloads, 'icon' => 'grid'],
            ['label' => __('Controls & Logs'), 'section' => $kubernetesSectionControls, 'icon' => 'clock'],
            ['label' => __('Events'), 'section' => $kubernetesSectionEvents, 'icon' => 'events'],
            ['label' => __('Audit Log'), 'section' => $kubernetesSectionAudit, 'icon' => 'check'],
        ]
        : [
            ['label' => __('Access'), 'section' => $kubernetesSectionAccess, 'icon' => 'bars'],
            ['label' => __('Workloads'), 'section' => $kubernetesSectionWorkloads, 'enterpriseFeature' => __('Kubernetes Workloads'), 'icon' => 'grid'],
            ['label' => __('Controls & Logs'), 'section' => $kubernetesSectionControls, 'enterpriseFeature' => __('Kubernetes Deployment Controls'), 'icon' => 'clock'],
            ['label' => __('Events & Audit'), 'section' => $kubernetesSectionEvents, 'enterpriseFeature' => __('Kubernetes Events & Audit'), 'icon' => 'events'],
        ];
@endphp

<div x-data="{ open: false }" class="contents" @keydown.escape.window="open = false" x-on:livewire:navigating.window="open = false" x-effect="document.body.classList.toggle('overflow-hidden', open)">

    {{-- Mobile trigger (hidden on sm+) --}}
    <div class="sm:hidden">
        <button
            type="button"
            @click="open = true"
            class="flex w-full items-center justify-between gap-2 rounded-xl border border-slate-700 bg-slate-950/90 px-4 py-3 text-sm text-slate-200 hover:border-slate-600 hover:text-white transition"
        >
            <span class="flex items-center gap-2">
                <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                <span class="text-xs uppercase tracking-[0.16em] text-slate-400">{{ $currentInfraLabel }}</span>
                <span class="font-medium text-white">{{ __('Navigation') }}</span>
            </span>
            <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </button>
    </div>

    {{-- Desktop: horizontal tabs (hidden on mobile) --}}
    <div class="hidden sm:flex flex-wrap gap-1 border-b border-slate-800">
        <a href="{{ route('infra.containers') }}"
            class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition {{ $isContainersActive ? 'border-indigo-500' : 'border-transparent text-slate-400 hover:text-slate-200 hover:border-slate-600' }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
            </svg>
            {{ __('Docker') }}
        </a>

        <a href="{{ route('infra.kubernetes') }}"
            class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition {{ $isKubernetesActive ? 'border-indigo-500' : 'border-transparent text-slate-400 hover:text-slate-200 hover:border-slate-600' }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z" />
            </svg>
            {{ __('Kubernetes') }}
        </a>
    </div>

    {{-- Mobile drawer (teleported to body) --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            wire:ignore
            class="sm:hidden fixed inset-0 z-[1100]"
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
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">{{ $currentInfraLabel }}</div>
                        <div class="mt-1 text-lg font-semibold text-white">{{ __('Navigation') }}</div>
                    </div>
                    <button type="button" @click="open = false" class="mt-0.5 rounded-lg p-1.5 text-slate-400 hover:text-white hover:bg-slate-800 transition shrink-0">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <nav class="p-4 space-y-1.5" aria-label="{{ $currentInfraLabel }} navigation">
                    <a
                        href="{{ route('infra.containers') }}"
                        {{-- wire:navigate.hover --}}
                        @click="open = false"
                        class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $isContainersActive ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                    >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                        {{ __('Docker') }}
                    </a>
                    <a
                        href="{{ route('infra.kubernetes') }}"
                        {{-- wire:navigate.hover --}}
                        @click="open = false"
                        class="group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition {{ $isKubernetesActive ? 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100' : 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white' }}"
                    >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z" />
                        </svg>
                        {{ __('Kubernetes') }}
                    </a>

                    @if ($isContainersActive)
                        <div class="mt-3 border-t border-slate-800 pt-4 text-[11px] uppercase tracking-[0.16em] text-slate-500">{{ __('Docker') }}</div>
                        @foreach ($containerDrawerSections as $drawerSection)
                            @if ($drawerSection['divider'] ?? false)
                                <div class="my-2 border-t border-slate-800"></div>
                                @continue
                            @endif

                            @php
                                $isEnterpriseLocked = isset($drawerSection['enterpriseFeature']) && ! $drawerIsEnterprise;
                                $isSectionActive = $drawerActiveSection === ($drawerSection['activeSection'] ?? $drawerSection['section']);
                                $drawerSectionClass = $drawerNavItem.' '.($isSectionActive ? $drawerActiveNav : $drawerIdleNav);
                                $drawerSectionIcon = $drawerSection['icon'] ?? 'bars';
                            @endphp

                            @if ($isEnterpriseLocked)
                                <button
                                    type="button"
                                    @click="open = false; window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: '{{ $drawerSection['enterpriseFeature'] }}' } }));"
                                    class="{{ $drawerSectionClass }} w-full text-left"
                                >
                                    @include('gitmanager-enterprise::livewire.infrastructure.partials.nav-icon', ['name' => $drawerSectionIcon])
                                    <span class="inline-flex items-center gap-1">
                                        {{ $drawerSection['label'] }}
                                        {!! $drawerLockIcon !!}
                                    </span>
                                </button>
                            @else
                                <a
                                    href="{{ route('infra.containers.section', $drawerSection['section']) }}"
                                    @click="open = false"
                                    class="{{ $drawerSectionClass }}"
                                >
                                    @include('gitmanager-enterprise::livewire.infrastructure.partials.nav-icon', ['name' => $drawerSectionIcon])
                                    <span class="inline-flex items-center gap-1">
                                        {{ $drawerSection['label'] }}
                                        @if ($drawerSection['showLock'] ?? false)
                                            {!! $drawerLockIcon !!}
                                        @endif
                                    </span>
                                </a>
                            @endif
                        @endforeach
                    @endif

                    @if ($isKubernetesActive)
                        <div class="mt-3 border-t border-slate-800 pt-4 text-[11px] uppercase tracking-[0.16em] text-slate-500">{{ __('Kubernetes') }}</div>
                        @foreach ($kubernetesDrawerSections as $drawerSection)
                            @php
                                $isEnterpriseLocked = isset($drawerSection['enterpriseFeature']) && ! $drawerIsEnterprise;
                                $isSectionActive = $drawerActiveSection === $drawerSection['section'];
                                $drawerSectionClass = $drawerNavItem.' '.($isSectionActive ? $drawerActiveNav : $drawerIdleNav);
                                $drawerSectionIcon = $drawerSection['icon'] ?? 'bars';
                            @endphp

                            @if ($isEnterpriseLocked)
                                <button
                                    type="button"
                                    @click="open = false; window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: '{{ $drawerSection['enterpriseFeature'] }}' } }));"
                                    class="{{ $drawerSectionClass }} w-full text-left"
                                >
                                    @include('gitmanager-enterprise::livewire.infrastructure.partials.nav-icon', ['name' => $drawerSectionIcon])
                                    <span class="inline-flex items-center gap-1">
                                        {{ $drawerSection['label'] }}
                                        {!! $drawerLockIcon !!}
                                    </span>
                                </button>
                            @else
                                <a
                                    href="{{ route('infra.kubernetes.section', $drawerSection['section']) }}"
                                    @click="open = false"
                                    class="{{ $drawerSectionClass }}"
                                >
                                    @include('gitmanager-enterprise::livewire.infrastructure.partials.nav-icon', ['name' => $drawerSectionIcon])
                                    {{ $drawerSection['label'] }}
                                </a>
                            @endif
                        @endforeach
                    @endif
                </nav>
            </div>
        </div>
    </template>
</div>
