@php
    use App\Services\NavigationStateService;
    $projectNavState = app(NavigationStateService::class)->projectsSidebarState(auth()->user());
    $isEnterprise = (bool) ($projectNavState['isEnterprise'] ?? false);
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ __('Total Projects') }}</p>
            <p class="mt-2 text-3xl font-bold text-slate-100">{{ $totalProjects }}</p>
        </div>
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ __('Healthy Sites') }}</p>
            <p class="mt-2 text-3xl font-bold text-emerald-400">{{ $healthyCount }}</p>
            @if ($monitoredProjects->count() > 0)
                <p class="mt-1 text-xs text-slate-500">{{ __('of :count monitored', ['count' => $monitoredProjects->count()]) }}</p>
            @endif
        </div>
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ __('Health Issues') }}</p>
            <p class="mt-2 text-3xl font-bold {{ $healthIssues->count() > 0 ? 'text-rose-600' : ' text-slate-100' }}">{{ $healthIssues->count() }}</p>
        </div>
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ __('Deployments Today') }}</p>
            <p class="mt-2 text-3xl font-bold text-indigo-400">{{ $deploymentsToday }}</p>
        </div>
    </div>

    <div class="hidden lg:block mt-8 border-t border-slate-800 pt-4 space-y-2">
        <div class="text-xs uppercase tracking-[0.12em] text-slate-500">{{ __('Bulk Actions') }}</div>
        <div class="flex flex-row  space-x-2">
            <button type="button" wire:click="checkAllHealth" wire:loading.attr="disabled" class="w-full px-3 py-2 text-xs rounded-md border border-emerald-400/50 text-emerald-200 hover:text-white hover:border-emerald-300 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed">
                <x-loading-spinner target="checkAllHealth" size="w-3 h-3" class="mr-1" />
                {{ __('Check Health') }}
            </button>
            <button type="button" wire:click="checkAllUpdates" wire:loading.attr="disabled" class="w-full px-3 py-2 text-xs rounded-md border border-indigo-400/50 text-indigo-200 hover:text-white hover:border-indigo-300 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed">
                <x-loading-spinner target="checkAllUpdates" size="w-3 h-3" class="mr-1" />
                {{ __('Check Updates') }}
            </button>
            @if ($isEnterprise)
                <button type="button" wire:click="auditAllProjects" wire:loading.attr="disabled" class="w-full px-3 py-2 text-xs rounded-md border border-emerald-400/50 text-emerald-200 hover:text-white hover:border-emerald-300 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed">
                    <x-loading-spinner target="auditAllProjects" size="w-3 h-3" class="mr-1" />
                    {{ __('Audit Projects') }}
                </button>
            @else
                <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Automatic Project & Container Audits' } }));" class="w-full px-3 py-2 text-xs rounded-md border border-amber-400/50 text-amber-200 hover:text-amber-100 hover:border-amber-300 inline-flex items-center justify-center">
                    <svg class="h-3.5 w-3.5 mr-1.5 shrink-0 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd"></path>
                    </svg>
                    {{ __('Audit Projects') }}
                </button>
            @endif
        </div>
    </div>

    {{-- Tabs --}}
    <div>
        {{-- Mobile: dropdown --}}
        <div class="sm:hidden relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <button
                type="button"
                @click="open = !open"
                class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-700 bg-slate-900/60 px-4 py-2.5 text-sm text-slate-200 hover:border-slate-600 transition"
                aria-haspopup="true"
                :aria-expanded="open"
            >
                <span>
                    @if ($tab === 'containers') {{ __('Containers') }}
                    @else {{ __('Projects') }}
                    @endif
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
                <button type="button" wire:click="setTab('projects')" @click="open = false"
                    class="flex w-full items-center px-4 py-3 text-sm transition {{ $tab === 'projects' ? 'bg-indigo-500/10 font-medium' : 'text-slate-200 hover:bg-slate-800' }}"
                    role="menuitem">{{ __('Projects') }}</button>
                <button type="button" wire:click="setTab('containers')" @click="open = false"
                    class="flex w-full items-center px-4 py-3 text-sm transition border-t border-slate-800 {{ $tab === 'containers' ? 'bg-indigo-500/10 font-medium' : 'text-slate-200 hover:bg-slate-800' }}"
                    role="menuitem">{{ __('Containers') }}</button>
            </div>

            <div class="mt-8 border-t border-slate-800 pt-4 space-y-2">
                <div class="text-xs uppercase tracking-[0.12em] text-slate-500">{{ __('Bulk Actions') }}</div>
                <button type="button" wire:click="checkAllHealth" wire:loading.attr="disabled" class="w-full px-3 py-2 text-xs rounded-md border border-emerald-400/50 text-emerald-200 hover:text-white hover:border-emerald-300 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed">
                    <x-loading-spinner target="checkAllHealth" size="w-3 h-3" class="mr-1" />
                    {{ __('Check Health') }}
                </button>
                <button type="button" wire:click="checkAllUpdates" wire:loading.attr="disabled" class="w-full px-3 py-2 text-xs rounded-md border border-indigo-400/50 text-indigo-200 hover:text-white hover:border-indigo-300 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed">
                    <x-loading-spinner target="checkAllUpdates" size="w-3 h-3" class="mr-1" />
                    {{ __('Check Updates') }}
                </button>
                @if ($isEnterprise)
                    <button type="button" wire:click="auditAllProjects" wire:loading.attr="disabled" class="w-full px-3 py-2 text-xs rounded-md border border-emerald-400/50 text-emerald-200 hover:text-white hover:border-emerald-300 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed">
                        <x-loading-spinner target="auditAllProjects" size="w-3 h-3" class="mr-1" />
                        {{ __('Audit Projects') }}
                    </button>
                @else
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Automatic Project & Container Audits' } }));" class="w-full px-3 py-2 text-xs rounded-md border border-amber-400/50 text-amber-200 hover:text-amber-100 hover:border-amber-300 inline-flex items-center justify-center">
                        <svg class="h-3.5 w-3.5 mr-1.5 shrink-0 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd"></path>
                        </svg>
                        {{ __('Audit Projects') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- Desktop: horizontal tabs --}}
        <div class="hidden sm:flex flex-wrap gap-2 border-b border-slate-800">
            <button type="button" wire:click="setTab('projects')"
                class="px-3 py-2 text-sm border-b-2 {{ $tab === 'projects' ? 'border-indigo-500' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
                {{ __('Projects') }}
            </button>
            <button type="button" wire:click="setTab('containers')"
                class="px-3 py-2 text-sm border-b-2 {{ $tab === 'containers' ? 'border-indigo-500' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
                {{ __('Containers') }}
            </button>
        </div>
    </div>

    {{-- Projects tab --}}
    @if ($tab === 'projects')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            {{-- Project directory monitor --}}
            <div class="lg:col-span-2 space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('Project Directory Monitor') }}</h2>
                    <a href="{{ route('projects.index') }}" class="text-xs text-indigo-400 hover:underline">{{ __('Manage') }} &rarr;</a>
                </div>

                @php
                    $rootProjects = $projectTree['projects'] ?? [];
                    $directoryNodes = array_values($projectTree['directories'] ?? []);
                    $hasProjects = ! empty($rootProjects) || ! empty($directoryNodes);
                @endphp

                @if (! $hasProjects)
                    <div class="rounded-xl border border-slate-800 bg-slate-900 p-8 text-center">
                        <svg class="mx-auto h-10 w-10 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.745 3.745 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.745 3.745 0 013.296-1.043A3.745 3.745 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.745 3.745 0 013.296 1.043 3.745 3.745 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                        </svg>
                        <p class="mt-3 text-sm text-slate-400">{{ __('No projects yet.') }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Create a project to begin tracking deployments and health checks.') }}</p>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($rootProjects as $project)
                            @include('livewire.dashboard.partials.project-row', [
                                'project' => $project,
                                'healthHistory' => $healthHistory,
                            ])
                        @endforeach

                        @foreach ($directoryNodes as $node)
                            @include('livewire.dashboard.partials.project-node', [
                                'node' => $node,
                                'healthHistory' => $healthHistory,
                            ])
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Right column: issues + recent deployments --}}
            <div class="space-y-6">

                {{-- Issues needing attention --}}
                @php
                    $issueCount = $healthIssues->count() + $updatesAvailable->count() + $vulnerableProjects->count();
                @endphp
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 mb-3">{{ __('Needs Attention') }}</h2>
                    @if ($issueCount === 0)
                        <div class="rounded-xl border border-slate-800 bg-slate-900 p-5 text-center">
                            <p class="text-sm text-emerald-400 font-medium">{{ __('All clear') }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ __('No issues detected.') }}</p>
                        </div>
                    @else
                        <div class="rounded-xl border border-slate-800 bg-slate-900 divide-y divide-slate-800 overflow-hidden">
                            @foreach ($healthIssues as $project)
                                <a href="{{ route('projects.show', $project) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-800/50 transition">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-rose-500"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-slate-200">{{ $project->name }}</p>
                                        @if ($project->health_issue_message)
                                            <p class="truncate text-xs text-slate-500">{{ $project->health_issue_message }}</p>
                                        @endif
                                    </div>
                                    <span class="shrink-0 text-xs font-medium text-rose-400">{{ __('Health') }}</span>
                                </a>
                            @endforeach
                            @foreach ($updatesAvailable as $project)
                                <a href="{{ route('projects.show', $project) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-800/50 transition">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-indigo-500"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-slate-200">{{ $project->name }}</p>
                                    </div>
                                    <span class="shrink-0 text-xs font-medium text-indigo-400">{{ __('Updates') }}</span>
                                </a>
                            @endforeach
                            @foreach ($vulnerableProjects as $project)
                                <a href="{{ route('projects.show', $project) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-800/50 transition">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-amber-500"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-slate-200">{{ $project->name }}</p>
                                        <p class="text-xs text-slate-500">{{ $project->audit_open_count }} {{ __( \Illuminate\Support\Str::plural('vulnerability', $project->audit_open_count) ) }}</p>
                                    </div>
                                    <span class="shrink-0 text-xs font-medium text-amber-400">{{ __('Security') }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Recent deployments --}}
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400 mb-3">{{ __('Recent Deployments') }}</h2>
                    @if ($recentDeployments->isEmpty())
                        <div class="rounded-xl border border-slate-800 bg-slate-900 p-5 text-center">
                            <p class="text-sm text-slate-400">{{ __('No deployments yet.') }}</p>
                        </div>
                    @else
                        <div class="rounded-xl border border-slate-800 bg-slate-900 divide-y divide-slate-800 overflow-hidden">
                            @foreach ($recentDeployments as $deployment)
                                <a href="{{ route('projects.show', $deployment->project) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-800/50 transition">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $deployment->status === 'success' ? 'bg-emerald-500' : ($deployment->status === 'running' ? 'bg-indigo-500 animate-pulse' : 'bg-rose-500') }}"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-slate-200">{{ $deployment->project?->name }}</p>
                                        <p class="text-xs text-slate-500">{{ $deployment->started_at?->diffForHumans() }}</p>
                                    </div>
                                    <span class="shrink-0 text-xs font-medium capitalize {{ $deployment->status === 'success' ? 'text-emerald-600' : ($deployment->status === 'running' ? 'text-indigo-600' : 'text-rose-400') }}">
                                        {{ $deployment->status }}
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>
        </div>
    @endif

    {{-- Containers tab --}}
    @if ($tab === 'containers')
        @if (! $infra['available'])
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-6 flex items-center gap-4">
                <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-slate-400"></span>
                <div>
                    <p class="text-sm font-medium text-slate-300">{{ __('Docker not available') }}</p>
                    <p class="text-xs text-slate-500">{{ __('Docker is not running or is not installed on this server.') }}</p>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                {{-- Left: summary tiles + container list --}}
                <div class="lg:col-span-2 space-y-4">

                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('Containers') }}</h2>
                        <a href="{{ route('infra.containers') }}" class="text-xs text-indigo-400 hover:underline">{{ __('Manage') }} →</a>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ __('Running') }}</p>
                            <p class="mt-1.5 text-2xl font-bold text-emerald-400">{{ $infra['containers']['running'] }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ __('Stopped') }}</p>
                            <p class="mt-1.5 text-2xl font-bold {{ $infra['containers']['stopped'] > 0 ? 'text-amber-600' : ' text-slate-100' }}">{{ $infra['containers']['stopped'] }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ __('Images') }}</p>
                            <p class="mt-1.5 text-2xl font-bold text-slate-100">{{ $infra['images'] }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ __('Volumes') }}</p>
                            <p class="mt-1.5 text-2xl font-bold text-slate-100">{{ $infra['volumes'] }}</p>
                        </div>
                    </div>

                    @if (count($infra['containers_list']) > 0)
                        @php
                            $containerRoot = $infra['container_tree'] ?? ['directories' => [], 'containers' => []];
                            $containerRootNodes = array_values($containerRoot['directories'] ?? []);
                            $rootContainers = $containerRoot['containers'] ?? [];
                        @endphp
                        <div class="space-y-3">
                            @foreach ($containerRootNodes as $node)
                                @include('livewire.dashboard.partials.container-node', ['node' => $node])
                            @endforeach

                            @foreach ($rootContainers as $container)
                                @include('livewire.dashboard.partials.container-row', ['container' => $container])
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Right: Docker summary + Swarm --}}
                <div class="space-y-4">

                    @if ($infra['is_enterprise'] && $infra['swarm'] !== null)
                        <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Swarm Cluster') }}</h3>
                                @if ($infra['swarm']['active'])
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        {{ __('Active') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-800 px-2 py-0.5 text-xs font-medium text-slate-400">
                                        {{ __('Inactive') }}
                                    </span>
                                @endif
                            </div>
                            @if ($infra['swarm']['active'])
                                <div class="space-y-3">
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-slate-400">{{ __('Nodes ready') }}</span>
                                        <span class="text-sm font-semibold {{ $infra['swarm']['ready_nodes'] === $infra['swarm']['nodes'] ? 'text-emerald-600' : 'text-amber-400' }}">
                                            {{ $infra['swarm']['ready_nodes'] }} / {{ $infra['swarm']['nodes'] }}
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-slate-400">{{ __('Services') }}</span>
                                        <span class="text-sm font-semibold text-slate-100">{{ $infra['swarm']['services'] }}</span>
                                    </div>
                                    @if ($infra['swarm']['nodes'] > 0)
                                        @php $nodePct = round($infra['swarm']['ready_nodes'] / $infra['swarm']['nodes'] * 100); @endphp
                                        <div class="h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
                                            <div class="h-full rounded-full {{ $nodePct === 100 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $nodePct }}%"></div>
                                        </div>
                                    @endif
                                </div>
                            @else
                                <p class="text-xs text-slate-500">{{ __('Swarm mode is not initialised on this host.') }}</p>
                            @endif
                        </div>
                    @endif

                    <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-4">{{ __('Docker Summary') }}</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-400">{{ __('Total containers') }}</span>
                                <span class="text-sm font-semibold text-slate-100">{{ $infra['containers']['total'] }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-400">{{ __('Running') }}</span>
                                <span class="text-sm font-semibold text-emerald-400">{{ $infra['containers']['running'] }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-400">{{ __('Stopped') }}</span>
                                <span class="text-sm font-semibold {{ $infra['containers']['stopped'] > 0 ? 'text-amber-600' : 'text-slate-100' }}">{{ $infra['containers']['stopped'] }}</span>
                            </div>
                            <div class="border-t border-slate-800 pt-3 flex justify-between items-center">
                                <span class="text-sm text-slate-400">{{ __('Images') }}</span>
                                <span class="text-sm font-semibold text-slate-100">{{ $infra['images'] }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-400">{{ __('Volumes') }}</span>
                                <span class="text-sm font-semibold text-slate-100">{{ $infra['volumes'] }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-400">{{ __('Networks') }}</span>
                                <span class="text-sm font-semibold text-slate-100">{{ $infra['networks'] }}</span>
                            </div>
                        </div>
                        @if ($infra['containers']['total'] > 0)
                            @php $runPct = round($infra['containers']['running'] / $infra['containers']['total'] * 100); @endphp
                            <div class="mt-4">
                                <div class="flex justify-between text-xs text-slate-500 mb-1">
                                    <span>{{ __('Running') }}</span><span>{{ $runPct }}%</span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
                                    <div class="h-full rounded-full bg-emerald-500" style="width: {{ $runPct }}%"></div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

            </div>
        @endif
    @endif

</div>
