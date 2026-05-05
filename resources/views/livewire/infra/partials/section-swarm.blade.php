@if (! $swarmInfo['active'])
    {{-- Swarm inactive --}}
    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-12 text-center space-y-4">
        <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z"/></svg>
        <div>
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Swarm is not active') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Initialise swarm description') }}</p>
        </div>
        <button wire:click="$set('showSwarmInit', true)"
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            {{ __('Initialize Swarm') }}
        </button>
    </div>

    {{-- Init modal --}}
    @if ($showSwarmInit)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showSwarmInit', false)">
            <div class="w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 px-6 py-4">
                    <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Initialise Docker Swarm') }}</h3>
                    <button wire:click="$set('showSwarmInit', false)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">{{ __('Advertise address') }} <span class="text-slate-400 font-normal">{{ __('(optional)') }}</span></label>
                        <input wire:model="swarmAdvertise" type="text" placeholder="192.168.1.100"
                               class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:border-indigo-400 focus:outline-none">
                        <p class="mt-1.5 text-xs text-slate-500">{{ __('Advertise address help') }}</p>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button wire:click="$set('showSwarmInit', false)" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100">{{ __('Cancel') }}</button>
                        <button wire:click="initSwarm" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition">{{ __('Initialize') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@else
    {{-- Swarm active --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach ([
            ['label' => 'Nodes',    'value' => count($swarmNodes),    'color' => 'indigo'],
            ['label' => 'Services', 'value' => count($swarmServices), 'color' => 'emerald'],
            ['label' => 'Managers', 'value' => collect($swarmNodes)->where('ManagerStatus', '!=', '')->count(), 'color' => 'violet'],
        ] as $c)
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                <p class="text-sm text-slate-500 dark:text-slate-400">@lang($c['label'])</p>
                <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">{{ $c['value'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Nodes --}}
    @if (count($swarmNodes) > 0)
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Nodes') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                        <tr>
                            @foreach (['Hostname', 'Status', 'Availability', 'Role', 'Engine Version'] as $h)
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">@lang($h)</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($swarmNodes as $node)
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ $node['Hostname'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @php $status = $node['Status'] ?? ''; @endphp
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ $status === 'Ready' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $status === 'Ready' ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                                        {{ $status ?: __('Unknown') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ $node['Availability'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @php $role = $node['ManagerStatus'] ? 'Manager' : 'Worker'; @endphp
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $role === 'Manager' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' }}">@lang($role)</span>
                                </td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ $node['EngineVersion'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Services --}}
    @if (count($swarmServices) > 0)
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Services') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/50">
                        <tr>
                            @foreach (['Name', 'Mode', 'Replicas', 'Image', 'Ports', 'Actions'] as $h)
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">@lang($h)</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($swarmServices as $svc)
                            @php
                                $svcName = $svc['Name'] ?? '';
                                preg_match('/(\d+)\/(\d+)/', $svc['Replicas'] ?? '', $m);
                                $ready   = (int) ($m[1] ?? 0);
                                $desired = (int) ($m[2] ?? 0);
                            @endphp
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ $svcName }}</td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ $svc['Mode'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1 text-xs font-medium {{ $ready === $desired ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                        {{ $svc['Replicas'] ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">{{ Str::limit($svc['Image'] ?? '', 35) }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $svc['Ports'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <button wire:click="openScaleModal('{{ $svcName }}', {{ $desired }})"
                                            class="text-xs px-2 py-1 rounded border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:border-indigo-300 hover:text-indigo-600 transition">@lang('Scale')</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Scale modal --}}
    @if ($showScale)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showScale', false)">
            <div class="w-full max-w-sm rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 px-6 py-4">
                    <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Scale Service') }}</h3>
                    <button wire:click="$set('showScale', false)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-sm text-slate-700 dark:text-slate-300">{{ __('Service:') }}: <span class="font-semibold">{{ $scaleService }}</span></p>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">{{ __('Replicas') }}</label>
                        <input wire:model="scaleReplicas" type="number" min="0" max="100"
                               class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:border-indigo-400 focus:outline-none">
                    </div>
                    <div class="flex justify-end gap-3">
                        <button wire:click="$set('showScale', false)" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100">{{ __('Cancel') }}</button>
                        <button wire:click="scaleService" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition">{{ __('Apply') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endif
