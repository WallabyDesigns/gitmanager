<div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @include('livewire.system.partials.tabs')

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Update Preferences</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Control how Git Web Manager checks for and applies updates.</p>
            </div>

            <div class="space-y-4">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="checkUpdates" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                    <span class="text-sm text-slate-600 dark:text-slate-300">
                        Check for updates
                        <span class="block text-xs text-slate-400 dark:text-slate-500">Shows update availability in the System area and navigation.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="autoUpdate" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                    <span class="text-sm text-slate-600 dark:text-slate-300">
                        Perform auto-updates
                        <span class="block text-xs text-slate-400 dark:text-slate-500">Runs the nightly self-update schedule when enabled.</span>
                    </span>
                </label>
                <div class="text-xs {{ $autoUpdate ? 'text-emerald-400' : 'text-slate-400 dark:text-slate-500' }}">
                    {{ $autoUpdate ? 'Auto-updates are enabled.' : 'Auto-updates are disabled. Manual updates remain available in System Updates.' }}
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">GitHub Security</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Control SSL verification for GitHub API calls.</p>
            </div>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="githubSslVerify" class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                <span class="text-sm text-slate-600 dark:text-slate-300">
                    Verify GitHub SSL certificates
                    <span class="block text-xs text-slate-400 dark:text-slate-500">Disable only if your host is missing CA certificates.</span>
                </span>
            </label>
            @if (! $githubSslVerify)
                <div class="text-xs text-rose-400">Warning: SSL verification is disabled. GitHub API calls are less secure.</div>
            @endif
        </div>

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Timezone</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Sets the default timezone used throughout the app.</p>
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Timezone</label>
                <select wire:model="timezone" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                    @foreach ($timezones as $tz)
                        <option value="{{ $tz }}">{{ $tz }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3" x-data="{ saved: false, timer: null }" x-on:settings-saved.window="
            saved = true;
            clearTimeout(timer);
            timer = setTimeout(() => saved = false, 2000);
        ">
            <button type="button" wire:click="save" wire:loading.attr="disabled" disabled wire:dirty.remove.attr="disabled" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center">
                <x-loading-spinner target="save" />
                Save Settings
            </button>
            <span wire:dirty class="text-xs text-amber-400">Settings are unsaved.</span>
            <span x-show="saved" x-transition.opacity.duration.200ms class="text-xs text-emerald-400">Settings saved.</span>
        </div>
    </div>
</div>
