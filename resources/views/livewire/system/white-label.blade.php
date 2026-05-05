<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs', ['systemTab' => 'white-label'])
            <div class="space-y-6">
                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div class="flex items-center gap-2">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ __('White Label Branding') }}</h3>
                <span class="inline-flex items-center gap-1 rounded-full border border-amber-300/70 bg-amber-50 px-2 py-0.5 text-[11px] uppercase tracking-wide text-amber-700 dark:border-amber-500/50 dark:bg-amber-500/10 dark:text-amber-300">
                    {{ __('Enterprise') }}
                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd" />
                    </svg>
                </span>
            </div>

            @if ($isEnterprise)
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Customize branding assets for your installation.') }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Brand Name') }}</label>
                        <input type="text" wire:model="whiteLabelName" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Your Brand Name" />
                        <x-input-error :messages="$errors->get('whiteLabelName')" class="mt-2" />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Logo URL') }}</label>
                        <input type="url" wire:model="whiteLabelLogoUrl" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="https://example.com/logo.svg" />
                        <x-input-error :messages="$errors->get('whiteLabelLogoUrl')" class="mt-2" />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Favicon URL') }}</label>
                        <input type="url" wire:model="whiteLabelFaviconUrl" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="https://example.com/favicon.ico" />
                        <x-input-error :messages="$errors->get('whiteLabelFaviconUrl')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3" x-data="{ saved: false, timer: null }" x-on:white-label-saved.window="
                    saved = true;
                    clearTimeout(timer);
                    timer = setTimeout(() => saved = false, 2000);
                ">
                    <button type="button" wire:click="save" wire:loading.attr="disabled" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center">
                        <x-loading-spinner target="save" />
                        {{ __('Save White Label Settings') }}
                    </button>
                    <span x-show="saved" x-transition.opacity.duration.200ms class="text-xs text-emerald-400">{{ __('Settings saved.') }}</span>
                </div>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Use your own logo, brand name, and favicon with Enterprise Edition.') }}
                </p>
                <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'White Label Branding' } }));" class="inline-flex items-center gap-2 rounded-md border border-amber-300/70 px-3 py-2 text-xs font-semibold text-amber-700 hover:text-amber-800 dark:border-amber-500/60 dark:text-amber-300 dark:hover:text-amber-200">
                    {{ __('Unlock White Label') }}
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd" />
                    </svg>
                </button>
            @endif
                </div>
            </div>
        </div>
    </div>
</div>
