<div class="flex items-center justify-between">
    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Volumes') }}</h2>
    <button wire:click="$set('showCreateVolume', true)"
            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        {{ __('New Volume') }}
    </button>
</div>

@if (count($volumes) === 0)
    <div class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 p-12 text-center">
        <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/></svg>
        <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-400">{{ __('No volumes found.') }}</p>
        <button wire:click="$set('showCreateVolume', true)" class="mt-4 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Create a volume →') }}</button>
    </div>
@else
    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        @foreach ([__('Name'), __('Driver'), __('Mountpoint'), __('Actions')] as $h)
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($volumes as $vol)
                        @php $name = $vol['Name'] ?? ''; @endphp
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ $name }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-md bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-xs font-medium text-slate-600 dark:text-slate-300">{{ $vol['Driver'] ?? 'local' }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">{{ Str::limit($vol['Mountpoint'] ?? '—', 50) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button wire:click="inspectVolume('{{ $name }}')" class="text-xs px-2 py-1 rounded border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:border-indigo-300 hover:text-indigo-600 transition">{{ __('Inspect') }}</button>
                                    <button wire:click="removeVolume('{{ $name }}')"
                                            wire:confirm="{{ __('Remove volume :name? Any data stored in it will be lost.', ['name' => $name]) }}"
                                            class="text-xs px-2 py-1 rounded border border-slate-200 dark:border-slate-700 text-slate-500 hover:border-rose-300 hover:text-rose-600 transition">{{ __('Remove') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- Create Volume Modal --}}
@if ($showCreateVolume)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showCreateVolume', false)">
        <div class="w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 px-6 py-4">
                <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Create Volume') }}</h3>
                <button wire:click="$set('showCreateVolume', false)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">{{ __('Volume Name') }}</label>
                    <input wire:model="newVolumeName" type="text" placeholder="my-volume"
                           class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:border-indigo-400 focus:outline-none">
                    @error('newVolumeName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">{{ __('Driver') }}</label>
                    <select wire:model="newVolumeDriver"
                            class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:border-indigo-400 focus:outline-none">
                        <option value="local">{{ __('local') }}</option>
                        <option value="tmpfs">{{ __('tmpfs') }}</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showCreateVolume', false)" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100">{{ __('Cancel') }}</button>
                    <button wire:click="createVolume" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition">{{ __('Create') }}</button>
                </div>
            </div>
        </div>
    </div>
@endif
