<div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3 flex-wrap">
                    <a href="{{ route('security.index', ['tab' => 'current']) }}" class="px-3 py-2 text-sm rounded-md {{ $tab === 'current' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300' }}">
                        Current Issues ({{ $openCount }})
                    </a>
                    <a href="{{ route('security.index', ['tab' => 'resolved']) }}" class="px-3 py-2 text-sm rounded-md {{ $tab === 'resolved' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300' }}">
                        Resolved Issues ({{ $resolvedCount }})
                    </a>
                    <a href="{{ route('security.index', ['tab' => 'users']) }}" class="px-3 py-2 text-sm rounded-md {{ $tab === 'users' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300' }}">
                        Users
                    </a>
                </div>
                @if ($tab !== 'users')
                    <button type="button" wire:click="sync" class="px-3 py-2 text-sm rounded-md border border-indigo-300 text-indigo-600 hover:text-indigo-800 dark:border-indigo-500/50 dark:text-indigo-300">
                        Sync Alerts
                    </button>
                @endif
            </div>
        </div>

        @if ($appUpdateFailed && $latestUpdate)
            <div class="bg-rose-500/10 border border-rose-500/30 text-rose-200 rounded-xl p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">Git Project Manager update failed</h3>
                        <p class="text-sm text-rose-100/80">Last attempt: {{ $latestUpdate->finished_at?->format('M j, Y g:i A') ?? 'Unknown time' }}</p>
                    </div>
                    <a href="{{ route('app-updates.index') }}" class="px-4 py-2 rounded-md bg-rose-500/20 text-rose-100 text-sm hover:bg-rose-500/30">
                        View update logs
                    </a>
                </div>
            </div>
        @endif

        @if ($tab === 'users')
            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
                <div>
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

                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Users</h3>
                    <div class="mt-3 space-y-3">
                        @forelse ($users as $user)
                            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
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
                            <p class="text-sm text-slate-500 dark:text-slate-400">No users found.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @else
            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
                <div class="space-y-4">
                    @forelse ($alerts as $alert)
                        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                        {{ $alert->package_name ?? 'Unknown package' }}
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ $alert->project->name }} · {{ $alert->ecosystem ?? 'unknown ecosystem' }}
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2 text-xs">
                                    <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        {{ $alert->state }}
                                    </span>
                                    @if ($alert->severity)
                                        <span class="px-2 py-1 rounded-full bg-rose-500/10 text-rose-300">
                                            {{ strtoupper($alert->severity) }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            @if ($alert->advisory_summary)
                                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $alert->advisory_summary }}</p>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-400 dark:text-slate-500">
                                @if ($alert->fixed_in)
                                    <span>Fixed in: {{ $alert->fixed_in }}</span>
                                @endif
                                @if ($alert->alert_created_at)
                                    <span>Created: {{ $alert->alert_created_at->format('M j, Y') }}</span>
                                @endif
                                @if ($alert->fixed_at)
                                    <span>Fixed: {{ $alert->fixed_at->format('M j, Y') }}</span>
                                @endif
                            </div>
                            <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                @if ($alert->advisory_url)
                                    <a href="{{ $alert->advisory_url }}" target="_blank" class="text-indigo-600 dark:text-indigo-300">Advisory</a>
                                @endif
                                @if ($alert->html_url)
                                    <a href="{{ $alert->html_url }}" target="_blank" class="text-indigo-600 dark:text-indigo-300">Dependabot Report</a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500 dark:text-slate-400">No issues found for this tab.</p>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</div>

