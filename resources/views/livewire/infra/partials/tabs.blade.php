@php
    $hasContainers = \Illuminate\Support\Facades\Route::has('infra.containers');
    $hasKubernetes = \Illuminate\Support\Facades\Route::has('infra.kubernetes');
    $component = $this ?? null;
    $isContainersComponent = is_object($component) && is_a($component, \App\Livewire\Infra\Containers::class);
    $isKubernetesComponent = is_object($component) && class_exists(\GitManagerEnterprise\Livewire\Infrastructure\Kubernetes::class)
        && is_a($component, \GitManagerEnterprise\Livewire\Infrastructure\Kubernetes::class);
    $isContainersActive = $hasContainers && (
        ($infraTab ?? null) === 'docker'
        || $isContainersComponent
        || (request()->routeIs('infra.containers*') && ! request()->routeIs('infra.kubernetes*'))
    );
    $isKubernetesActive = $hasKubernetes && (
        ($infraTab ?? null) === 'kubernetes'
        || $isKubernetesComponent
        || request()->routeIs('infra.kubernetes*')
    );
    $currentInfraLabel = $isKubernetesActive ? 'Kubernetes' : 'Docker';
    $showMobileDropdown = $hasContainers && $hasKubernetes;
@endphp

<div>
    @if ($showMobileDropdown)
        {{-- Mobile: dropdown (hidden on sm+) --}}
        <div class="sm:hidden relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <button
                type="button"
                @click="open = !open"
                class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-700 bg-slate-900/60 px-4 py-2.5 text-sm text-slate-200 hover:border-slate-600 transition"
                aria-haspopup="true"
                :aria-expanded="open"
            >
                <span class="flex items-center gap-2">
                    @if ($isKubernetesActive)
                        <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z" />
                        </svg>
                    @else
                        <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                    @endif
                    {{ $currentInfraLabel }}
                </span>
                <svg class="h-4 w-4 text-slate-400 transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
            <div
                x-show="open"
                x-cloak
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="absolute left-0 right-0 top-full mt-1 z-50 rounded-lg border border-slate-700 bg-slate-900 shadow-lg overflow-hidden"
                role="menu"
            >
                <a
                    href="{{ route('infra.containers') }}"
                    class="flex items-center gap-2 px-4 py-3 text-sm transition {{ $isContainersActive ? 'bg-indigo-500/10 font-medium' : 'text-slate-200 hover:bg-slate-800' }}"
                    role="menuitem"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                    </svg>
                    {{ __('Docker') }}
                </a>
                <a
                    href="{{ route('infra.kubernetes') }}"
                    class="flex items-center gap-2 px-4 py-3 text-sm transition border-t border-slate-800 {{ $isKubernetesActive ? 'bg-indigo-500/10 font-medium' : 'text-slate-200 hover:bg-slate-800' }}"
                    role="menuitem"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z" />
                    </svg>
                    {{ __('Kubernetes') }}
                </a>
            </div>
        </div>
    @endif

    {{-- Desktop: horizontal tabs (hidden on mobile when dropdown is shown) --}}
    <div class="{{ $showMobileDropdown ? 'hidden sm:flex' : 'flex' }} flex-wrap gap-1 border-b border-slate-800">
        @if ($hasContainers)
            <a href="{{ route('infra.containers') }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition {{ $isContainersActive ? 'border-indigo-500' : 'border-transparent text-slate-400 hover:text-slate-200 hover:border-slate-600' }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>
                {{ __('Docker') }}
            </a>
        @endif
        @if ($hasKubernetes)
            <a href="{{ route('infra.kubernetes') }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition {{ $isKubernetesActive ? 'border-indigo-500' : 'border-transparent text-slate-400 hover:text-slate-200 hover:border-slate-600' }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z" />
                </svg>
                {{ __('Kubernetes') }}
            </a>
        @endif
    </div>
</div>
