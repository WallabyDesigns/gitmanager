<div class="flex items-center justify-between">
    <h2 class="text-base font-semibold text-slate-100">{{ __('Networks') }}</h2>
    <button wire:click="$set('showCreateNetwork', true)"
            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        {{ __('New Network') }}
    </button>
</div>

@if (count($networks) === 0)
    <div class="rounded-xl border border-dashed border-slate-700 p-12 text-center">
        <p class="text-sm font-medium text-slate-400">{{ __('No networks found.') }}</p>
    </div>
@else
    <div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-800/50">
                    <tr>
                        @foreach ([__('Name'), __('Driver'), __('Scope'), __('Network ID'), __('Actions')] as $h)
                            <th class="px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($networks as $net)
                        @php $name = $net['Name'] ?? ''; @endphp
                        <tr class="hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium text-slate-100">{{ $name }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-md bg-slate-800 px-2 py-0.5 text-xs font-medium text-slate-300">{{ $net['Driver'] ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-400 text-xs">{{ $net['Scope'] ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ Str::limit($net['ID'] ?? '', 14) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button wire:click="inspectNetwork('{{ $name }}')" class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-400 hover:border-indigo-300 hover:text-indigo-600 transition">{{ __('Inspect') }}</button>
                                    <button wire:click="openCloneNetworkModal('{{ $name }}', '{{ $net['Driver'] ?? 'bridge' }}')" class="text-xs px-2 py-1 rounded border border-indigo-500/60 text-indigo-300 hover:text-indigo-100 transition">{{ __('Edit') }}</button>
                                    @if (! in_array($name, ['bridge', 'host', 'none']))
                                        <button wire:click="removeNetwork('{{ $name }}')"
                                                wire:confirm="{{ __('Remove network :name?', ['name' => $name]) }}"
                                                class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-500 hover:border-rose-300 hover:text-rose-600 transition">{{ __('Remove') }}</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- Clone/Edit Network Modal --}}
@if ($showCloneNetwork ?? false)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showCloneNetwork', false)">
        <div class="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 px-6 py-4">
                <h3 class="font-semibold text-slate-100">{{ __('Edit Network') }}</h3>
                <button wire:click="$set('showCloneNetwork', false)" class="text-slate-400 hover:text-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-xs text-slate-400">
                    {{ __('Docker networks are immutable for most settings, so edits create a new network based on the selected source.') }}
                </p>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('Source network') }}</label>
                    <input wire:model.defer="cloneSourceNetwork" type="text"
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    @error('cloneSourceNetwork') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('New network name') }}</label>
                    <input wire:model.defer="cloneNetworkName" type="text" placeholder="my-network-v2"
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    @error('cloneNetworkName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('Driver') }}</label>
                    <select wire:model.defer="cloneNetworkDriver"
                            class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                        <option value="bridge">{{ __('bridge') }}</option>
                        <option value="overlay">{{ __('overlay') }}</option>
                        <option value="macvlan">{{ __('macvlan') }}</option>
                        <option value="host">host</option>
                        <option value="none">{{ __('none') }}</option>
                    </select>
                    @error('cloneNetworkDriver') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showCloneNetwork', false)" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-slate-100">{{ __('Cancel') }}</button>
                    <button wire:click="cloneNetwork" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition">{{ __('Create Edited Network') }}</button>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Create Network Modal --}}
@if ($showCreateNetwork)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showCreateNetwork', false)">
        <div class="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 px-6 py-4">
                <h3 class="font-semibold text-slate-100">{{ __('Create Network') }}</h3>
                <button wire:click="$set('showCreateNetwork', false)" class="text-slate-400 hover:text-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('Network Name') }}</label>
                    <input wire:model="newNetworkName" type="text" placeholder="my-network"
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    @error('newNetworkName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('Driver') }}</label>
                    <select wire:model="newNetworkDriver"
                            class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                        <option value="bridge">{{ __('bridge') }}</option>
                        <option value="overlay">{{ __('overlay') }}</option>
                        <option value="macvlan">{{ __('macvlan') }}</option>
                        <option value="host">host</option>
                        <option value="none">{{ __('none') }}</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showCreateNetwork', false)" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-slate-100">{{ __('Cancel') }}</button>
                    <button wire:click="createNetwork" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition">{{ __('Create') }}</button>
                </div>
            </div>
        </div>
    </div>
@endif
