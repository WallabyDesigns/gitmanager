<div class="flex items-center justify-between">
    <h2 class="text-base font-semibold text-slate-100">{{ __('Containers') }}</h2>
    <button wire:click="$set('showCreate', true)"
            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        {{ __('New Container') }}
    </button>
</div>

@if (count($containers) === 0)
    <div class="rounded-xl border border-dashed border-slate-700 p-12 text-center">
        <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>
        <p class="mt-3 text-sm font-medium text-slate-400">{{ __('No containers found.') }}</p>
        <button wire:click="$set('showCreate', true)" class="mt-4 text-sm text-indigo-400 hover:underline">{{ __('Create a container →') }}</button>
    </div>
@else
    <div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-800/50">
                    <tr>
                        @foreach (['Name', 'Image', 'Status', 'Ports', 'Actions'] as $h)
                            <th class="px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">{{ __($h) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($containers as $c)
                        @php $state = $c['State'] ?? ''; $id = $c['ID'] ?? ''; @endphp
                        <tr class="hover:bg-slate-800/30">
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-100">{{ $c['Names'] ?? $id }}</span>
                                <span class="ml-2 font-mono text-xs text-slate-400">{{ Str::limit($id, 12) }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ Str::limit($c['Image'] ?? '', 35) }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $state === 'running' ? 'bg-emerald-100 : ($state === 'exited' ? 'bg-rose-500/10 text-rose-300' : 'bg-slate-800 text-slate-400') }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $state === 'running' ? 'bg-emerald-500' : ($state === 'exited' ? 'bg-rose-500' : 'bg-slate-400') }}"></span>
                                    {{ $state ? ucfirst($state) : __('Unknown') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ Str::limit($c['Ports'] ?? '', 35) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2 flex-wrap">
                                    @if ($state === 'running')
                                        <button wire:click="stopContainer('{{ $id }}')" class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-400 hover:border-rose-300 hover:text-rose-600 transition">{{ __('Stop') }}</button>
                                        <button wire:click="restartContainer('{{ $id }}')" class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-400 hover:border-amber-300 hover:text-amber-600 transition">{{ __('Restart') }}</button>
                                        <button wire:click="viewLogs('{{ $id }}')" class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-400 hover:border-indigo-300 hover:text-indigo-600 transition">{{ __('Logs') }}</button>
                                    @else
                                        <button wire:click="startContainer('{{ $id }}')" class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-400 hover:border-emerald-300 hover:text-emerald-600 transition">{{ __('Start') }}</button>
                                    @endif
                                    <button wire:click="openEditModal('{{ $id }}')" class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-400 hover:border-indigo-300 hover:text-indigo-600 transition">{{ __('Edit') }}</button>
                                    <button wire:click="inspectContainer('{{ $id }}')" class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-400 hover:border-slate-400 transition">{{ __('Inspect') }}</button>
                                    <button wire:click="removeContainer('{{ $id }}')"
                                            wire:confirm="{{ __('Remove container :name? This cannot be undone.', ['name' => $c['Names'] ?? $id]) }}"
                                            class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-500 hover:border-rose-300 hover:text-rose-600 transition">{{ __('Remove') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
