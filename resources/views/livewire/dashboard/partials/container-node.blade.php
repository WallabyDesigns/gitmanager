@php
    $directories = array_values($node['directories'] ?? []);
    $containersInNode = $node['containers'] ?? [];
    $stats = $node['stats'] ?? [];
    $folderPath = (string) ($node['path'] ?? $node['name'] ?? 'containers');
    $folderKey = 'gwm-dashboard-container-folder:'.md5($folderPath);
    $total = (int) ($stats['total'] ?? 0);
    $running = (int) ($stats['running'] ?? 0);
    $runPct = $total > 0 ? round($running / $total * 100) : 0;
@endphp

<details
    wire:key="dashboard-container-folder-{{ md5($folderPath) }}"
    class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-slate-950/20 p-3"
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
                <div class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                    <svg class="h-4 w-4 text-indigo-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M2 4.75A1.75 1.75 0 013.75 3h4.57c.464 0 .909.184 1.237.513l1.18 1.18c.328.328.773.512 1.237.512h4.269A1.75 1.75 0 0118 6.955v8.295A1.75 1.75 0 0116.25 17H3.75A1.75 1.75 0 012 15.25V4.75z" />
                    </svg>
                    <span class="truncate">{{ $node['name'] ?? __('Group') }}</span>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        {{ trans_choice(':count container|:count containers', $total, ['count' => $total]) }}
                    </span>
                    <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                        {{ __('Running') }} {{ $running }}
                    </span>
                    @if (($stats['stopped'] ?? 0) > 0)
                        <span class="rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ __('Stopped') }} {{ $stats['stopped'] }}</span>
                    @endif
                    @if ($total > 0)
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $runPct }}%</span>
                    @endif
                </div>
            </div>
            <svg class="h-4 w-4 text-slate-400 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </div>
    </summary>

    <div class="mt-3 space-y-3 border-l border-slate-200 pl-3 dark:border-slate-800">
        @foreach ($directories as $childNode)
            @include('livewire.dashboard.partials.container-node', ['node' => $childNode])
        @endforeach

        @foreach ($containersInNode as $container)
            @include('livewire.dashboard.partials.container-row', ['container' => $container])
        @endforeach
    </div>
</details>
