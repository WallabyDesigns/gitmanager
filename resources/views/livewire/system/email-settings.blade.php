<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs', ['systemTab' => 'email'])
            <div class="space-y-6">
                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ __('SMTP Configuration') }}</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Update mail settings used by workflow notifications and password resets.') }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Mailer') }}</label>
                    <input type="text" wire:model.defer="mailer" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Host') }}</label>
                    <input type="text" wire:model.defer="host" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Port') }}</label>
                    <input type="text" wire:model.defer="port" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Encryption') }}</label>
                    <input type="text" wire:model.defer="encryption" placeholder="tls/ssl/none" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Username') }}</label>
                    <input type="text" wire:model.defer="username" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('Password') }}</label>
                    <input type="password" wire:model.defer="password" placeholder="Leave blank to keep current" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('From Address') }}</label>
                    <input type="email" wire:model.defer="fromAddress" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('From Name') }}</label>
                    <input type="text" wire:model.defer="fromName" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="button" wire:click="save" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500">
                    {{ __('Save Settings') }}
                </button>
            </div>
        </div>

                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ __('Send Test Email') }}</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Verify that outgoing mail is configured correctly.') }}</p>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input type="email" wire:model.defer="testRecipient" placeholder="you@example.com" class="w-full sm:flex-1 rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                <button type="button" wire:click="sendTest" class="px-4 py-2 rounded-md border border-slate-200 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                    {{ __('Send Test') }}
                </button>
            </div>
                </div>
            </div>
        </div>
    </div>
</div>
