<div class="space-y-6">
    <div>
<h3 class="text-lg font-semibold text-slate-100">{{ __('Environment') }}</h3>
        <p class="mt-1 text-sm text-slate-400">{{ __('Edit the project\'s .env file directly.') }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ __('Path:') }} {{ $envPath }}</p>
    </div>

    <div class="rounded-lg border border-slate-800 p-4 space-y-4">
        @if ($envStatus)
            <div class="text-xs text-amber-300">{{ $envStatus }}</div>
        @endif

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="reloadEnv" class="px-3 py-1.5 text-xs rounded-md border border-slate-700 text-slate-300 hover:text-slate-100 inline-flex items-center">
                <x-loading-spinner target="reloadEnv" />
                Reload
            </button>
            @if (! $envExists && $envExampleExists)
                <button type="button" wire:click="createFromExample" class="px-3 py-1.5 text-xs rounded-md border hover:text-emerald-900 border-emerald-500/40 text-emerald-300 inline-flex items-center">
                    <x-loading-spinner target="createFromExample" />
                    Create From {{ $envExampleLabel ?? '.env.example' }}
                </button>
            @endif
        </div>

        <textarea
            rows="16"
            class="block w-full rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border-slate-700 bg-slate-900 text-slate-100 font-mono text-xs"
            wire:model.defer="envContent"
            placeholder="APP_ENV=local&#10;APP_KEY=&#10;APP_DEBUG=true"
        ></textarea>

        <div class="flex justify-end">
            <button type="button" wire:click="save" class="px-3 py-2 text-sm rounded-md hover:bg-slate-700 bg-slate-100 text-slate-900 inline-flex items-center">
                <x-loading-spinner target="save" />
                Save .env
            </button>
        </div>
    </div>
</div>
