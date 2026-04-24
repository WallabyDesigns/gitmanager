@php
    use App\Services\NavigationStateService;

    $systemNavState = app(NavigationStateService::class)->systemSidebarState(auth()->user());
    $openAlerts = (int) ($systemNavState['openAlerts'] ?? 0);
    $updateAvailable = (bool) ($systemNavState['updateAvailable'] ?? false);
    $isEnterprise = (bool) ($systemNavState['isEnterprise'] ?? false);
    $showLocalLicenseBadge = (bool) ($systemNavState['showLocalLicenseBadge'] ?? false);

    $navItem = 'group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition';
    $activeNav = 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100';
    $idleNav = 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white';

    $currentSystemPage = match (true) {
        request()->routeIs('system.updates') => 'App Updates',
        request()->routeIs('system.support') => 'Enterprise Support',
        request()->routeIs('system.scheduler') => 'Scheduler & Queue',
        request()->routeIs('system.application') => 'App & Security',
        request()->routeIs('system.audits') => 'Audits & Alerts',
        request()->routeIs('system.licensing') => 'Edition & License',
        request()->routeIs('system.environment') => 'Environment Config',
        request()->routeIs('system.email') => 'Email Settings',
        request()->routeIs('system.white-label') => 'White Label',
        default => 'System',
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
                <span class="text-xs uppercase tracking-[0.16em] text-slate-400">System</span>
                <span class="font-medium text-white">{{ $currentSystemPage }}</span>
                @if ($openAlerts > 0)
                    <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $openAlerts }}</span>
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
                <div class="text-xs uppercase tracking-[0.16em] text-slate-400">System</div>
                <div class="mt-1 text-lg font-semibold text-white">Control Center</div>
                <div class="text-xs text-slate-400">Updates, security, settings, and platform services.</div>
            </div>

            <nav class="mt-4 space-y-1.5" aria-label="System navigation">
                <a href="{{ route('system.updates') }}" wire:navigate.hover class="{{ $navItem }} {{ request()->routeIs('system.updates') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    <span class="inline-flex items-center gap-2">
                        App Updates
                        @if ($updateAvailable)
                            <span class="inline-flex items-center justify-center rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">New</span>
                        @endif
                    </span>
                </a>

                @if ($isEnterprise)
                    <a href="{{ route('system.support') }}" wire:navigate.hover class="{{ $navItem }} {{ request()->routeIs('system.support') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                        </svg>
                        Enterprise Support
                    </a>
                @else
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Support' } }));" class="{{ $navItem }} {{ $idleNav }} w-full">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                        </svg>
                        <span class="inline-flex items-center gap-1">
                            Enterprise Support
                            <svg class="h-3.5 w-3.5 shrink-0 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd"></path>
                            </svg>
                        </span>
                    </button>
                @endif

                <a href="{{ route('system.scheduler') }}" wire:navigate.hover class="{{ $navItem }} {{ request()->routeIs('system.scheduler') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 5H3"/><path d="M16 12H3"/><path d="M9 19H3"/><path d="m16 16-3 3 3 3"/><path d="M21 5v12a2 2 0 0 1-2 2h-6"/></svg>
                    <span class="inline-flex items-center gap-2">
                        Scheduler & Queue
                        @if ($openAlerts > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $openAlerts }}</span>
                        @endif
                    </span>
                </a>

                <a href="{{ route('system.application') }}" wire:navigate.hover class="{{ $navItem }} {{ request()->routeIs('system.application') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                    App & Security
                </a>

                <a href="{{ route('system.audits') }}" wire:navigate.hover class="{{ $navItem }} {{ request()->routeIs('system.audits') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" /></svg>
                    Audits & Alerts
                </a>

                <a href="{{ route('system.licensing') }}" wire:navigate.hover class="{{ $navItem }} {{ request()->routeIs('system.licensing') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>
                    <span class="inline-flex items-center gap-2">
                        Edition & License
                        @if ($showLocalLicenseBadge)
                            <span class="inline-flex items-center justify-center rounded-full bg-amber-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">Fix</span>
                        @endif
                    </span>
                </a>

                <a href="{{ route('system.environment') }}" wire:navigate.hover class="{{ $navItem }} {{ request()->routeIs('system.environment') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" /></svg>
                    Environment Config
                </a>

                <a href="{{ route('system.email') }}" wire:navigate.hover class="{{ $navItem }} {{ request()->routeIs('system.email') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                    Email Settings
                </a>

                @if ($isEnterprise)
                    <a href="{{ route('system.white-label') }}" wire:navigate.hover class="{{ $navItem }} {{ request()->routeIs('system.white-label') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                        White Label
                    </a>
                @else
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'White Label Branding' } }));" class="{{ $navItem }} {{ $idleNav }} w-full">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                        <span class="inline-flex items-center gap-1">
                            White Label
                            <svg class="h-3.5 w-3.5 shrink-0 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd"></path>
                            </svg>
                        </span>
                    </button>
                @endif
            </nav>
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
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">System</div>
                        <div class="mt-1 text-lg font-semibold text-white">Control Center</div>
                        <div class="text-xs text-slate-400">Updates, security, settings, and platform services.</div>
                    </div>
                    <button type="button" @click="open = false" class="mt-0.5 rounded-lg p-1.5 text-slate-400 hover:text-white hover:bg-slate-800 transition shrink-0">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <nav class="p-4 space-y-1.5" aria-label="System navigation">
                    <a href="{{ route('system.updates') }}" wire:navigate.hover @click="open = false" class="{{ $navItem }} {{ request()->routeIs('system.updates') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        <span class="inline-flex items-center gap-2">
                            App Updates
                            @if ($updateAvailable)
                                <span class="inline-flex items-center justify-center rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">New</span>
                            @endif
                        </span>
                    </a>

                    @if ($isEnterprise)
                        <a href="{{ route('system.support') }}" wire:navigate.hover @click="open = false" class="{{ $navItem }} {{ request()->routeIs('system.support') ? $activeNav : $idleNav }}">
                            <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                            </svg>
                            Enterprise Support
                        </a>
                    @else
                        <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Support' } })); open = false" class="{{ $navItem }} {{ $idleNav }} w-full">
                            <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                            </svg>
                            <span class="inline-flex items-center gap-1">
                                Enterprise Support
                                <svg class="h-3 w-3 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                            </span>
                        </button>
                    @endif

                    <a href="{{ route('system.scheduler') }}" wire:navigate.hover @click="open = false" class="{{ $navItem }} {{ request()->routeIs('system.scheduler') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 5H3"/><path d="M16 12H3"/><path d="M9 19H3"/><path d="m16 16-3 3 3 3"/><path d="M21 5v12a2 2 0 0 1-2 2h-6"/></svg>
                        <span class="inline-flex items-center gap-2">
                            Scheduler & Queue
                            @if ($openAlerts > 0)
                                <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $openAlerts }}</span>
                            @endif
                        </span>
                    </a>

                    <a href="{{ route('system.application') }}" wire:navigate.hover @click="open = false" class="{{ $navItem }} {{ request()->routeIs('system.application') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                        App & Security
                    </a>

                    <a href="{{ route('system.audits') }}" wire:navigate.hover @click="open = false" class="{{ $navItem }} {{ request()->routeIs('system.audits') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" /></svg>
                        Audits & Alerts
                    </a>

                    <a href="{{ route('system.licensing') }}" wire:navigate.hover @click="open = false" class="{{ $navItem }} {{ request()->routeIs('system.licensing') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>
                        <span class="inline-flex items-center gap-2">
                            Edition & License
                            @if ($showLocalLicenseBadge)
                                <span class="inline-flex items-center justify-center rounded-full bg-amber-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">Fix</span>
                            @endif
                        </span>
                    </a>

                    <a href="{{ route('system.environment') }}" wire:navigate.hover @click="open = false" class="{{ $navItem }} {{ request()->routeIs('system.environment') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" /></svg>
                        Environment Config
                    </a>

                    <a href="{{ route('system.email') }}" wire:navigate.hover @click="open = false" class="{{ $navItem }} {{ request()->routeIs('system.email') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                        Email Settings
                    </a>

                    @if ($isEnterprise)
                        <a href="{{ route('system.white-label') }}" wire:navigate.hover @click="open = false" class="{{ $navItem }} {{ request()->routeIs('system.white-label') ? $activeNav : $idleNav }}">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                            White Label
                        </a>
                    @else
                        <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'White Label Branding' } })); open = false" class="{{ $navItem }} {{ $idleNav }} w-full">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                            <span class="inline-flex items-center gap-1">
                                White Label
                                <svg class="h-3 w-3 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                            </span>
                        </button>
                    @endif
                </nav>
            </div>
        </div>
    </template>
</div>
