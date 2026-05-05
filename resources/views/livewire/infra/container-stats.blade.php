<div wire:init="loadStats">
    @if ($dockerAvailable && (count($stats) > 0 || $summary['total'] > 0))
        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900/60 overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    <span class="text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wide">{{ __('Docker') }}</span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        {{ $summary['running'] }}/{{ $summary['total'] }} {{ __('running') }}
                    </span>
                </div>
                <a href="{{ route('infra.containers') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                    {{ __('Manage') }} →
                </a>
            </div>

            @if (count($stats) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/40">
                                @foreach (['Container', 'CPU', 'Memory', 'Net I/O'] as $h)
                                    <th class="px-3 py-2 text-left font-medium text-slate-400 dark:text-slate-500 uppercase tracking-wide">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/50">
                            @foreach ($stats as $s)
                                @php $cpu = (float) rtrim($s['CPUPerc'] ?? '0%', '%'); @endphp
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20">
                                    <td class="px-3 py-2 font-medium text-slate-800 dark:text-slate-200">{{ $s['Name'] ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-1.5">
                                            <div class="w-12 h-1 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                                                <div class="h-full rounded-full {{ $cpu > 80 ? 'bg-rose-500' : ($cpu > 50 ? 'bg-amber-400' : 'bg-emerald-500') }}"
                                                     style="width: {{ min($cpu, 100) }}%"></div>
                                            </div>
                                            <span class="{{ $cpu > 80 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400' }}">{{ $s['CPUPerc'] ?? '—' }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-slate-500 dark:text-slate-400">{{ $s['MemUsage'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-slate-500 dark:text-slate-400">{{ $s['NetIO'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">{{ __('No running containers.') }}</p>
            @endif
        </div>
    @endif
</div>
