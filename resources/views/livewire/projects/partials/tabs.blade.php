@php
    use App\Services\NavigationStateService;

    $showBulkActions = $showBulkActions ?? false;
    $explicitProjectsTab = $projectsTab ?? null;
    $tab = $explicitProjectsTab ?? (request()->routeIs('projects.create') ? 'create' : 'list');
    $projectNavState = app(NavigationStateService::class)->projectsSidebarState(auth()->user());
    $isAdmin = (bool) ($projectNavState['isAdmin'] ?? false);
    $isEnterprise = (bool) ($projectNavState['isEnterprise'] ?? false);

    $isFtpRoute = ($isFtpRoute ?? false)
        || $explicitProjectsTab === 'ftp-accounts'
        || request()->routeIs('ftp-accounts.*');

    $activeTabClass = 'border-indigo-500 text-white';
    $idleTabClass = 'border-transparent text-slate-400 hover:text-slate-200';
@endphp

<div class="min-w-0">
    <div class="flex flex-wrap items-end justify-between gap-2 border-b border-slate-800">
        <nav class="flex flex-wrap gap-1" aria-label="{{ __('Projects navigation') }}">
            <a href="{{ route('projects.index') }}"
               class="px-3 py-2 text-sm border-b-2 -mb-px {{ $tab === 'list' && ! $isFtpRoute ? $activeTabClass : $idleTabClass }}">
                {{ __('Projects') }}
            </a>
            <a href="{{ route('projects.create') }}"
               class="px-3 py-2 text-sm border-b-2 -mb-px {{ $tab === 'create' && ! $isFtpRoute ? $activeTabClass : $idleTabClass }}">
                {{ __('Create Project') }}
            </a>
            @if ($isAdmin)
                <a href="{{ route('ftp-accounts.index') }}"
                   class="px-3 py-2 text-sm border-b-2 -mb-px {{ $isFtpRoute ? $activeTabClass : $idleTabClass }}">
                    {{ __('Remote Access') }}
                </a>
            @endif
        </nav>

        @if ($showBulkActions && $tab === 'list' && ! $isFtpRoute)
            <div class="flex flex-wrap items-center gap-2 pb-2">
                <label class="block">
                    <span class="sr-only">{{ __('Search projects') }}</span>
                    <span class="gwm-system-search flex items-center gap-2 rounded-md border border-slate-700 bg-slate-900 px-3 py-1.5 transition-colors focus-within:border-indigo-400/60">
                        <svg class="h-3.5 w-3.5 shrink-0 text-slate-500 pointer-events-none" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/>
                        </svg>
                        <input
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search projects…') }}"
                            aria-label="{{ __('Search projects') }}"
                            class="min-w-0 w-36 border-0 bg-transparent p-0 text-xs text-slate-300 placeholder-slate-500 focus:outline-none focus:ring-0"
                        />
                        @if ($search !== '')
                            <button type="button" wire:click="$set('search', '')" class="shrink-0 text-slate-500 transition-colors hover:text-slate-300" aria-label="{{ __('Clear search') }}">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        @endif
                    </span>
                </label>
                <button type="button" wire:click="checkAllHealth" wire:loading.attr="disabled" class="px-3 py-1.5 text-xs rounded-md border border-emerald-400/50 text-emerald-200 hover:text-white hover:border-emerald-300 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                    <x-loading-spinner target="checkAllHealth" size="w-3 h-3" class="mr-1" />
                    {{ __('Check Health') }}
                </button>
                <button type="button" wire:click="checkAllUpdates" wire:loading.attr="disabled" class="px-3 py-1.5 text-xs rounded-md border border-indigo-400/50 text-indigo-200 hover:text-white hover:border-indigo-300 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                    <x-loading-spinner target="checkAllUpdates" size="w-3 h-3" class="mr-1" />
                    {{ __('Check Updates') }}
                </button>
                @if ($isEnterprise)
                    <button type="button" wire:click="auditAllProjects" wire:loading.attr="disabled" class="px-3 py-1.5 text-xs rounded-md border border-emerald-400/50 text-emerald-200 hover:text-white hover:border-emerald-300 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                        <x-loading-spinner target="auditAllProjects" size="w-3 h-3" class="mr-1" />
                        {{ __('Audit Projects') }}
                    </button>
                @else
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Automatic Project & Container Audits' } }));" class="px-3 py-1.5 text-xs rounded-md border border-amber-400/50 text-amber-200 hover:text-amber-100 hover:border-amber-300 inline-flex items-center">
                        <svg class="h-3.5 w-3.5 mr-1.5 shrink-0 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd"></path>
                        </svg>
                        {{ __('Audit Projects') }}
                    </button>
                @endif
            </div>
        @endif
    </div>
</div>
