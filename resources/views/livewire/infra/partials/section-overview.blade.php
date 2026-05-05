@php
    $running  = collect($containers)->where('State', 'running')->count();
    $stopped  = collect($containers)->whereNotIn('State', ['running'])->count();
    $totalCpu = collect($containerStats)->sum(fn($s) => (float) rtrim($s['CPUPerc'] ?? '0%', '%'));
@endphp

{{-- Stats Cards --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    @foreach ([
        ['label' => 'Running',  'value' => $running,              'color' => 'emerald', 'icon' => 'M5.636 5.636a9 9 0 1012.728 0M12 3v9'],
        ['label' => 'Stopped',  'value' => $stopped,              'color' => 'slate',   'icon' => 'M5.25 7.5A2.25 2.25 0 017.5 5.25h9a2.25 2.25 0 012.25 2.25v9a2.25 2.25 0 01-2.25 2.25h-9a2.25 2.25 0 01-2.25-2.25v-9z'],
        ['label' => 'Images',   'value' => count($images),        'color' => 'indigo',  'icon' => 'M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0l4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0l-5.571 3-5.571-3'],
        ['label' => 'Volumes',  'value' => count($volumes),       'color' => 'violet',  'icon' => 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125'],
    ] as $card)
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-{{ $card['color'] }}-50 dark:bg-{{ $card['color'] }}-500/10">
                    <svg class="h-4 w-4 text-{{ $card['color'] }}-600 dark:text-{{ $card['color'] }}-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $card['icon'] }}"/>
                    </svg>
                </span>
            </div>
            <p class="mt-3 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $card['value'] }}</p>
        </div>
    @endforeach
</div>

{{-- Resource usage --}}
@if (count($containerStats) > 0)
    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Container Resource Usage') }}</h3>
            <button wire:click="loadPageData" class="text-xs text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 flex items-center gap-1">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                {{ __('Refresh') }}
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        @foreach (['Name', 'CPU %', 'Memory', 'Net I/O', 'Block I/O', 'PIDs'] as $h)
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($containerStats as $stat)
                        @php $cpu = (float) rtrim($stat['CPUPerc'] ?? '0%', '%'); @endphp
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ $stat['Name'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-16 h-1.5 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                                        <div class="h-full rounded-full {{ $cpu > 80 ? 'bg-rose-500' : ($cpu > 50 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                                             style="width: {{ min($cpu, 100) }}%"></div>
                                    </div>
                                    <span class="text-xs {{ $cpu > 80 ? 'text-rose-600' : ($cpu > 50 ? 'text-amber-600' : 'text-slate-600 dark:text-slate-400') }}">{{ $stat['CPUPerc'] ?? '—' }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ $stat['MemUsage'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ $stat['NetIO'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ $stat['BlockIO'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ $stat['PIDs'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- Recent containers --}}
@if (count($containers) > 0)
    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ __('All Containers') }}</h3>
            <a href="{{ route('infra.containers.section', 'containers') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Manage') }} →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        @foreach (['Name', 'Image', 'Status', 'Ports', ''] as $h)
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($containers as $c)
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ $c['Names'] ?? $c['ID'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400 font-mono text-xs">{{ Str::limit($c['Image'] ?? '', 40) }}</td>
                            <td class="px-4 py-3">
                                @php $state = $c['State'] ?? ''; @endphp
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $state === 'running' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $state === 'running' ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                                    {{ ucfirst($state) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400 font-mono text-xs">{{ Str::limit($c['Ports'] ?? '', 40) }}</td>
                            <td class="px-4 py-3">
                                @if ($state === 'running')
                                    <button wire:click="stopContainer('{{ $c['ID'] }}')" class="text-xs text-slate-500 hover:text-rose-600 dark:hover:text-rose-400">{{ __('Stop') }}</button>
                                @else
                                    <button wire:click="startContainer('{{ $c['ID'] }}')" class="text-xs text-slate-500 hover:text-emerald-600 dark:hover:text-emerald-400">{{ __('Start') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
