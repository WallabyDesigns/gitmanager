@php
    $categories = collect($templates)->groupBy('category')->sortKeys();
@endphp

<div class="flex items-center justify-between">
    <div>
        <h2 class="text-base font-semibold text-slate-100">{{ __('Templates') }}</h2>
        <p class="text-sm text-slate-400 mt-0.5">{{ __('One-click deploy for common services. Customise before deploying via :action.', ['action' => '<em>'.__('Configure').'</em>']) }}</p>
    </div>
</div>

@foreach ($categories as $category => $group)
    <div>
        <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">{{ $category }}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($group as $key => $tpl)
                @php
                    $colorMap = [
                        'emerald' => ['bg-emerald-500/10', 'border-emerald-500/20', 'text-emerald-400'],
                        'blue'    => ['bg-blue-500/10',       'border-blue-500/20',       'text-blue-400'],
                        'indigo'  => ['bg-indigo-500/10',   'border-indigo-500/20',   'text-indigo-400'],
                        'rose'    => ['bg-rose-500/10',       'border-rose-500/20',       'text-rose-400'],
                        'green'   => ['bg-green-500/10',     'border-green-500/20',     'text-green-400'],
                        'amber'   => ['bg-amber-500/10',     'border-amber-500/20',     'text-amber-400'],
                        'sky'     => ['bg-sky-500/10',         'border-sky-500/20',         'text-sky-400'],
                        'violet'  => ['bg-violet-500/10',   'border-violet-500/20',   'text-violet-400'],
                        'orange'  => ['bg-orange-500/10',   'border-orange-500/20',   'text-orange-400'],
                        'teal'    => ['bg-teal-500/10',       'border-teal-500/20',       'text-teal-400'],
                        'lime'    => ['bg-lime-500/10',       'border-lime-500/20',       'text-lime-400'],
                        'pink'    => ['bg-pink-500/10',       'border-pink-500/20',       'text-pink-400'],
                    ];
                    [$iconBg, $iconBorder, $iconColor] = $colorMap[$tpl['color']] ?? $colorMap['indigo'];
                @endphp
                <div class="rounded-xl border border-slate-800 bg-slate-900 p-5 flex flex-col gap-4">
                    <div class="flex items-start gap-3">
                        <div class="shrink-0 flex h-9 w-9 items-center justify-center rounded-lg {{ $iconBg }} border {{ $iconBorder }}">
                            <svg class="h-4 w-4 {{ $iconColor }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/>
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h4 class="font-semibold text-slate-100 text-sm">{{ $tpl['name'] }}</h4>
                            <p class="text-xs text-slate-400 mt-0.5">{{ $tpl['description'] }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1">
                        <span class="font-mono text-xs bg-slate-800 text-slate-400 rounded px-1.5 py-0.5">{{ $tpl['image'] }}</span>
                        @foreach (array_filter($tpl['ports'] ?? []) as $port)
                            <span class="text-xs bg-slate-800 text-slate-400 rounded px-1.5 py-0.5">:{{ Str::afterLast($port, ':') }}</span>
                        @endforeach
                    </div>

                    <div class="flex gap-2 mt-auto">
                        <button wire:click="loadTemplateIntoForm('{{ $key }}')"
                                class="flex-1 rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:border-indigo-300 hover:text-indigo-300 transition">
                            Configure
                        </button>
                        @if ($dockerAvailable)
                            <button wire:click="deployTemplate('{{ $key }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="deployTemplate('{{ $key }}')"
                                    class="flex-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-3 py-1.5 text-xs font-semibold text-white transition disabled:opacity-50">
                                <span wire:loading.delay wire:target="deployTemplate('{{ $key }}')">
                                    <svg class="inline h-3 w-3 animate-spin mr-1" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                </span>
                                Deploy
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endforeach
