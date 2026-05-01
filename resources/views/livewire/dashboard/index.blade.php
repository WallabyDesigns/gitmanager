<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6" wire:poll.60s="refreshHealth">

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Total Projects</p>
            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $totalProjects }}</p>
        </div>
        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Healthy Sites</p>
            <p class="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ $healthyCount }}</p>
            @if ($monitoredProjects->count() > 0)
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">of {{ $monitoredProjects->count() }} monitored</p>
            @endif
        </div>
        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Health Issues</p>
            <p class="mt-2 text-3xl font-bold {{ $healthIssues->count() > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-slate-100' }}">{{ $healthIssues->count() }}</p>
        </div>
        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Deployments Today</p>
            <p class="mt-2 text-3xl font-bold text-indigo-600 dark:text-indigo-400">{{ $deploymentsToday }}</p>
        </div>
    </div>

    {{-- Tabs --}}
    <div>
        {{-- Mobile: dropdown --}}
        <div class="sm:hidden relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <button
                type="button"
                @click="open = !open"
                class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-200/70 dark:border-slate-700 bg-white dark:bg-slate-900/60 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600 transition"
                aria-haspopup="true"
                :aria-expanded="open"
            >
                <span>
                    @if ($tab === 'containers') Containers
                    @else Projects
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
                class="absolute left-0 right-0 top-full mt-1 z-50 rounded-lg border border-slate-200/70 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-lg overflow-hidden"
                role="menu"
            >
                <button type="button" wire:click="setTab('projects')" @click="open = false"
                    class="flex w-full items-center px-4 py-3 text-sm transition {{ $tab === 'projects' ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 font-medium' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}"
                    role="menuitem">Projects</button>
                <button type="button" wire:click="setTab('containers')" @click="open = false"
                    class="flex w-full items-center px-4 py-3 text-sm transition border-t border-slate-100 dark:border-slate-800 {{ $tab === 'containers' ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 font-medium' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}"
                    role="menuitem">Containers</button>
            </div>
        </div>

        {{-- Desktop: horizontal tabs --}}
        <div class="hidden sm:flex flex-wrap gap-2 border-b border-slate-200/70 dark:border-slate-800">
            <button type="button" wire:click="setTab('projects')"
                class="px-3 py-2 text-sm border-b-2 {{ $tab === 'projects' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
                Projects
            </button>
            <button type="button" wire:click="setTab('containers')"
                class="px-3 py-2 text-sm border-b-2 {{ $tab === 'containers' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
                Containers
            </button>
        </div>
    </div>

    {{-- Projects tab --}}
    @if ($tab === 'projects')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            {{-- Health monitor --}}
            <div class="lg:col-span-2 space-y-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Site Health Monitor</h2>

                @if ($monitoredProjects->isEmpty())
                    <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-8 text-center">
                        <svg class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.745 3.745 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.745 3.745 0 013.296-1.043A3.745 3.745 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.745 3.745 0 013.296 1.043 3.745 3.745 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                        </svg>
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No projects with health monitoring configured.</p>
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Add a site URL or health check URL to a project to enable monitoring.</p>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($monitoredProjects as $project)
                            @php
                                $status = $project->health_status;
                                $isOk = $status === 'ok';
                                $isNa = $status === 'na';
                                $history = $healthHistory[$project->id] ?? collect();
                                $uptimePercent = $history->count() > 0
                                    ? round($history->where('status', 'success')->count() / $history->count() * 100)
                                    : null;
                            @endphp
                            <a href="{{ route('projects.show', $project) }}" class="block rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 transition hover:border-indigo-300 dark:hover:border-indigo-500/60 hover:shadow-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $isOk ? 'bg-emerald-500' : ($isNa ? 'bg-rose-500' : 'bg-slate-400') }}"></span>
                                            <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $project->name }}</span>
                                            @if ($project->directory_path)
                                                <span class="hidden sm:inline text-xs text-slate-400 dark:text-slate-500 truncate">{{ $project->directory_path }}</span>
                                            @endif
                                        </div>
                                        @if ($project->site_url || $project->health_url)
                                            <p class="mt-0.5 ml-4 text-xs text-slate-400 dark:text-slate-500 truncate">{{ $project->health_url ?: $project->site_url }}</p>
                                        @endif
                                    </div>
                                    <div class="shrink-0 flex items-center gap-4">
                                        @if ($history->count() > 0)
                                            <div class="hidden sm:flex items-end gap-0.5 h-5">
                                                @foreach ($history->take(30) as $check)
                                                    <span class="w-1.5 rounded-sm {{ $check->status === 'success' ? 'bg-emerald-400 dark:bg-emerald-500' : 'bg-rose-400 dark:bg-rose-500' }}" style="height: {{ $check->status === 'success' ? '100%' : '50%' }}"></span>
                                                @endforeach
                                            </div>
                                            @if ($uptimePercent !== null)
                                                <span class="text-xs font-medium {{ $uptimePercent >= 95 ? 'text-emerald-600 dark:text-emerald-400' : ($uptimePercent >= 75 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') }}">{{ $uptimePercent }}%</span>
                                            @endif
                                        @endif
                                        <span class="text-xs font-semibold uppercase tracking-wide px-2 py-1 rounded-full {{ $isOk ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($isNa ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400') }}">
                                            {{ $isOk ? 'OK' : ($isNa ? 'Down' : 'Unknown') }}
                                        </span>
                                    </div>
                                </div>
                                @if ($project->health_checked_at)
                                    <p class="mt-2 ml-4 text-xs text-slate-400 dark:text-slate-500">
                                        Last checked {{ $project->health_checked_at->diffForHumans() }}
                                    </p>
                                @endif
                            </a>
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
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-3">Needs Attention</h2>
                    @if ($issueCount === 0)
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 text-center">
                            <p class="text-sm text-emerald-600 dark:text-emerald-400 font-medium">All clear</p>
                            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">No issues detected.</p>
                        </div>
                    @else
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 divide-y divide-slate-100 dark:divide-slate-800 overflow-hidden">
                            @foreach ($healthIssues as $project)
                                <a href="{{ route('projects.show', $project) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-rose-500"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $project->name }}</p>
                                        @if ($project->health_issue_message)
                                            <p class="truncate text-xs text-slate-400 dark:text-slate-500">{{ $project->health_issue_message }}</p>
                                        @endif
                                    </div>
                                    <span class="shrink-0 text-xs font-medium text-rose-600 dark:text-rose-400">Health</span>
                                </a>
                            @endforeach
                            @foreach ($updatesAvailable as $project)
                                <a href="{{ route('projects.show', $project) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-indigo-500"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $project->name }}</p>
                                    </div>
                                    <span class="shrink-0 text-xs font-medium text-indigo-600 dark:text-indigo-400">Updates</span>
                                </a>
                            @endforeach
                            @foreach ($vulnerableProjects as $project)
                                <a href="{{ route('projects.show', $project) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-amber-500"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $project->name }}</p>
                                        <p class="text-xs text-slate-400 dark:text-slate-500">{{ $project->audit_open_count }} {{ Str::plural('vulnerability', $project->audit_open_count) }}</p>
                                    </div>
                                    <span class="shrink-0 text-xs font-medium text-amber-600 dark:text-amber-400">Security</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Recent deployments --}}
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-3">Recent Deployments</h2>
                    @if ($recentDeployments->isEmpty())
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 text-center">
                            <p class="text-sm text-slate-500 dark:text-slate-400">No deployments yet.</p>
                        </div>
                    @else
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 divide-y divide-slate-100 dark:divide-slate-800 overflow-hidden">
                            @foreach ($recentDeployments as $deployment)
                                <a href="{{ route('projects.show', $deployment->project) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $deployment->status === 'success' ? 'bg-emerald-500' : ($deployment->status === 'running' ? 'bg-indigo-500 animate-pulse' : 'bg-rose-500') }}"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $deployment->project?->name }}</p>
                                        <p class="text-xs text-slate-400 dark:text-slate-500">{{ $deployment->started_at?->diffForHumans() }}</p>
                                    </div>
                                    <span class="shrink-0 text-xs font-medium capitalize {{ $deployment->status === 'success' ? 'text-emerald-600 dark:text-emerald-400' : ($deployment->status === 'running' ? 'text-indigo-600 dark:text-indigo-400' : 'text-rose-600 dark:text-rose-400') }}">
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
            <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 flex items-center gap-4">
                <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-slate-400"></span>
                <div>
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Docker not available</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">Docker is not running or is not installed on this server.</p>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                {{-- Left: summary tiles + container list --}}
                <div class="lg:col-span-2 space-y-4">

                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Containers</h2>
                        <a href="{{ route('infra.containers') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Manage →</a>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Running</p>
                            <p class="mt-1.5 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $infra['containers']['running'] }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Stopped</p>
                            <p class="mt-1.5 text-2xl font-bold {{ $infra['containers']['stopped'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-slate-100' }}">{{ $infra['containers']['stopped'] }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Images</p>
                            <p class="mt-1.5 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $infra['images'] }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Volumes</p>
                            <p class="mt-1.5 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ $infra['volumes'] }}</p>
                        </div>
                    </div>

                    @if (count($infra['containers_list']) > 0)
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
                            <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">All Containers</h3>
                            </div>
                            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($infra['containers_list'] as $container)
                                    @php
                                        $isRunning = ($container['State'] ?? '') === 'running';
                                        $cName = ltrim($container['Names'] ?? $container['ID'] ?? '?', '/');
                                    @endphp
                                    <div class="flex items-center gap-3 px-4 py-2.5">
                                        <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $isRunning ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $cName }}</p>
                                            <p class="truncate text-xs text-slate-400 dark:text-slate-500">{{ $container['Image'] ?? '' }}</p>
                                        </div>
                                        <span class="shrink-0 text-xs font-medium capitalize {{ $isRunning ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400' }}">
                                            {{ $container['State'] ?? 'unknown' }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Right: Docker summary + Swarm --}}
                <div class="space-y-4">

                    @if ($infra['is_enterprise'] && $infra['swarm'] !== null)
                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Swarm Cluster</h3>
                                @if ($infra['swarm']['active'])
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 dark:bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                                        Inactive
                                    </span>
                                @endif
                            </div>
                            @if ($infra['swarm']['active'])
                                <div class="space-y-3">
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-slate-500 dark:text-slate-400">Nodes ready</span>
                                        <span class="text-sm font-semibold {{ $infra['swarm']['ready_nodes'] === $infra['swarm']['nodes'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                            {{ $infra['swarm']['ready_nodes'] }} / {{ $infra['swarm']['nodes'] }}
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-slate-500 dark:text-slate-400">Services</span>
                                        <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $infra['swarm']['services'] }}</span>
                                    </div>
                                    @if ($infra['swarm']['nodes'] > 0)
                                        @php $nodePct = round($infra['swarm']['ready_nodes'] / $infra['swarm']['nodes'] * 100); @endphp
                                        <div class="h-1.5 w-full rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                            <div class="h-full rounded-full {{ $nodePct === 100 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $nodePct }}%"></div>
                                        </div>
                                    @endif
                                </div>
                            @else
                                <p class="text-xs text-slate-400 dark:text-slate-500">Swarm mode is not initialised on this host.</p>
                            @endif
                        </div>
                    @endif

                    <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-4">Docker Summary</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-500 dark:text-slate-400">Total containers</span>
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $infra['containers']['total'] }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-500 dark:text-slate-400">Running</span>
                                <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">{{ $infra['containers']['running'] }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-500 dark:text-slate-400">Stopped</span>
                                <span class="text-sm font-semibold {{ $infra['containers']['stopped'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-slate-100' }}">{{ $infra['containers']['stopped'] }}</span>
                            </div>
                            <div class="border-t border-slate-100 dark:border-slate-800 pt-3 flex justify-between items-center">
                                <span class="text-sm text-slate-500 dark:text-slate-400">Images</span>
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $infra['images'] }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-500 dark:text-slate-400">Volumes</span>
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $infra['volumes'] }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-500 dark:text-slate-400">Networks</span>
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $infra['networks'] }}</span>
                            </div>
                        </div>
                        @if ($infra['containers']['total'] > 0)
                            @php $runPct = round($infra['containers']['running'] / $infra['containers']['total'] * 100); @endphp
                            <div class="mt-4">
                                <div class="flex justify-between text-xs text-slate-400 dark:text-slate-500 mb-1">
                                    <span>Running</span><span>{{ $runPct }}%</span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
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
