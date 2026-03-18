<div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @include('livewire.users.partials.tabs')
        @include('livewire.partials.mail-warning', [
            'mailConfigured' => $mailConfigured,
            'showMailSettingsLink' => $showMailSettingsLink,
        ])

        <div >
            @if ($tab === 'create')
                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Create User</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Generate accounts for teammates. New users must change their password on first login.</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="user_name" value="Name" />
                            <x-text-input id="user_name" class="mt-1 block w-full" wire:model.live="userForm.name" />
                            <x-input-error :messages="$errors->get('userForm.name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="user_email" value="Email" />
                            <x-text-input id="user_email" class="mt-1 block w-full" wire:model.live="userForm.email" />
                            <x-input-error :messages="$errors->get('userForm.email')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="user_password" value="Temporary Password (optional)" />
                            <x-text-input id="user_password" class="mt-1 block w-full" wire:model.live="userForm.password" />
                            <x-input-error :messages="$errors->get('userForm.password')" class="mt-2" />
                        </div>
                        <div class="flex items-center gap-2 pt-6">
                            <input id="user_force_change" type="checkbox" wire:model.live="userForm.require_password_change" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                            <label for="user_force_change" class="text-sm text-slate-600 dark:text-slate-300">Require password change on first login</label>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <button type="button" wire:click="createUser" class="px-4 py-2 rounded-md bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900">
                            Create User
                        </button>
                    </div>
                    @if ($generatedPassword)
                        <div class="mt-4 rounded-lg border border-amber-300/60 bg-amber-500/10 p-4 text-sm text-amber-200">
                            Temporary password for user #{{ $generatedUserId }}: <span class="font-mono">{{ $generatedPassword }}</span>
                        </div>
                    @endif
                </div>
            @endif

            @if ($tab === 'list')
                <div>

                    @forelse ($users as $user)
                        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $user->name }}</div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</div>
                                </div>
                                <div class="flex flex-wrap gap-2 text-xs">
                                    @if ($user->must_change_password)
                                        <span class="px-2 py-1 rounded-full bg-amber-500/20 text-amber-200">Password change required</span>
                                    @endif
                                    <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Joined {{ $user->created_at?->format('M j, Y') }}</span>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                <button type="button" wire:click="sendPasswordReset({{ $user->id }})" class="text-indigo-600 dark:text-indigo-300">
                                    Send reset link
                                </button>
                                <button type="button" wire:click="resetPassword({{ $user->id }})" class="text-rose-500">
                                    Generate temp password
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-slate-300/70 dark:border-slate-700 p-6 text-sm text-slate-500 dark:text-slate-400">
                            No users found.
                        </div>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</div>
