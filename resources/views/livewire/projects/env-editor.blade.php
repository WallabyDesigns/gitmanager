<div class="space-y-6">
    <div>
        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Environment</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Edit the project's <code>.env</code> file directly.</p>
        @if ($envPath)
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Path: {{ $envPath }}</p>
        @endif
    </div>

    <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4 space-y-4">
        @if ($envStatus)
            <div class="text-xs text-amber-700 dark:text-amber-300">{{ $envStatus }}</div>
        @endif

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="reloadEnv" class="px-3 py-1.5 text-xs rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 inline-flex items-center">
                <x-loading-spinner target="reloadEnv" />
                Reload
            </button>
            @if (! $envExists && $envExampleExists)
                <button type="button" wire:click="createFromExample" class="px-3 py-1.5 text-xs rounded-md border border-emerald-300 text-emerald-700 hover:text-emerald-900 dark:border-emerald-500/40 dark:text-emerald-300 inline-flex items-center">
                    <x-loading-spinner target="createFromExample" />
                    Create From {{ $envExampleLabel ?? '.env.example' }}
                </button>
            @endif
        </div>

        <textarea
            rows="16"
            class="block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 font-mono text-xs"
            wire:model.defer="envContent"
            placeholder="APP_ENV=local&#10;APP_KEY=&#10;APP_DEBUG=true"
        ></textarea>

        <div class="flex justify-end">
            <button type="button" wire:click="save" class="px-3 py-2 text-sm rounded-md bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 inline-flex items-center">
                <x-loading-spinner target="save" />
                Save .env
            </button>
        </div>
    </div>
</div>
