@php
    $directories = array_values($node['directories'] ?? []);
    $projectsInNode = $node['projects'] ?? [];
    $stats = $node['stats'] ?? [];
    $folderPath = (string) ($node['path'] ?? $node['name'] ?? 'projects');
    $folderKey = 'gwm-dashboard-project-folder:'.md5($folderPath);
    $healthy = (int) ($stats['healthy'] ?? 0);
    $monitored = (int) ($stats['monitored'] ?? 0);
    $healthPct = $monitored > 0 ? round($healthy / $monitored * 100) : null;
@endphp

<details
    wire:key="dashboard-project-folder-{{ md5($folderPath) }}"
    class="rounded-xl border border-slate-800 bg-slate-950/20 p-3"
    x-data="{
        key: @js($folderKey),
        open: false,
        init() {
            const stored = localStorage.getItem(this.key);
            this.open = stored === null ? true : stored === 'true';
            this.$el.open = this.open;
        },
    }"
    x-bind:open="open"
    @toggle="open = $el.open; localStorage.setItem(key, open ? 'true' : 'false')"
>
    <summary class="cursor-pointer list-none">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 text-sm font-semibold text-slate-100">
                    <svg class="h-4 w-4 text-indigo-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M2 4.75A1.75 1.75 0 013.75 3h4.57c.464 0 .909.184 1.237.513l1.18 1.18c.328.328.773.512 1.237.512h4.269A1.75 1.75 0 0118 6.955v8.295A1.75 1.75 0 0116.25 17H3.75A1.75 1.75 0 012 15.25V4.75z" />
                    </svg>
                    <span class="truncate">{{ $node['name'] ?? __('Folder') }}</span>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2 py-1 text-xs bg-slate-800 text-slate-300">
                        {{ trans_choice(':count project|:count projects', (int) ($stats['total'] ?? 0), ['count' => (int) ($stats['total'] ?? 0)]) }}
                    </span>
                    @if ($healthPct !== null)
                        <span class="rounded-full px-2 py-1 text-xs {{ $healthPct >= 95 ? 'bg-emerald-500/10 text-emerald-300' : 'bg-amber-500/10 text-amber-300' }}">
                            {{ __('Health') }} {{ $healthPct }}%
                        </span>
                    @endif
                    @if (($stats['down'] ?? 0) > 0)
                        <span class="rounded-full px-2 py-1 text-xs bg-rose-500/10 text-rose-300">{{ __('Down') }} {{ $stats['down'] }}</span>
                    @endif
                    @if (($stats['updates'] ?? 0) > 0)
                        <span class="rounded-full px-2 py-1 text-xs bg-indigo-500/10 text-indigo-300">{{ __('Updates') }} {{ $stats['updates'] }}</span>
                    @endif
                    @if (($stats['vulnerabilities'] ?? 0) > 0)
                        <span class="rounded-full px-2 py-1 text-xs bg-amber-500/10 text-amber-300">{{ __('Vulnerabilities') }} {{ $stats['vulnerabilities'] }}</span>
                    @endif
                    @if (($stats['deployments_today'] ?? 0) > 0)
                        <span class="rounded-full px-2 py-1 text-xs bg-slate-800 text-slate-300">{{ __('Deployments Today') }} {{ $stats['deployments_today'] }}</span>
                    @endif
                </div>
            </div>
            <svg class="h-4 w-4 text-slate-400 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </div>
    </summary>

    <div class="mt-3 space-y-3 border-l pl-3 border-slate-800">
        @foreach ($directories as $childNode)
            @include('livewire.dashboard.partials.project-node', [
                'node' => $childNode,
                'healthHistory' => $healthHistory,
            ])
        @endforeach

        @foreach ($projectsInNode as $project)
            @include('livewire.dashboard.partials.project-row', [
                'project' => $project,
                'healthHistory' => $healthHistory,
            ])
        @endforeach
    </div>
</details>
