@if ($showEdit)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showEdit', false)">
        <div class="w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 px-6 py-4">
                <h3 class="font-semibold text-slate-900 dark:text-slate-100">Edit Container</h3>
                <button wire:click="$set('showEdit', false)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-xs text-slate-500 dark:text-slate-400">Container: <code class="font-mono">{{ $editTarget }}</code></p>
                <p class="text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-700/40 rounded-lg px-3 py-2">
                    Only resource limits and restart policy can be updated live. To change image, ports, or env — remove and recreate the container.
                </p>

                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Restart policy</label>
                    <select wire:model="editForm.restart"
                            class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:border-indigo-400 focus:outline-none">
                        <option value="">no</option>
                        <option value="unless-stopped">unless-stopped</option>
                        <option value="always">always</option>
                        <option value="on-failure">on-failure</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Memory limit</label>
                        <input wire:model="editForm.memory" type="text" placeholder="512m, 2g …"
                               class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:border-indigo-400 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">CPU limit</label>
                        <input wire:model="editForm.cpus" type="text" placeholder="0.5, 2 …"
                               class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:border-indigo-400 focus:outline-none">
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button wire:click="$set('showEdit', false)" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100">Cancel</button>
                    <button wire:click="updateContainer" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition">Update</button>
                </div>
            </div>
        </div>
    </div>
@endif
