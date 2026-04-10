<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @include('livewire.ftp-accounts.partials.tabs')

        @if ($tab === 'create')
            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $editingId ? 'Edit FTP/SSH Access' : 'Create FTP/SSH Access' }}</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Store FTP/SSH credentials securely and reuse them across projects.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="ftp_name" value="Account Name" />
                        <x-text-input id="ftp_name" class="mt-1 block w-full" wire:model.live="form.name" />
                        <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ftp_host" value="Host" />
                        <x-text-input id="ftp_host" class="mt-1 block w-full" wire:model.live="form.host" placeholder="ftp.example.com" />
                        <x-input-error :messages="$errors->get('form.host')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ftp_port" value="Port" />
                        <x-text-input id="ftp_port" class="mt-1 block w-full" wire:model.live="form.port" />
                        <x-input-error :messages="$errors->get('form.port')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ssh_port" value="SSH Port (optional)" />
                        <x-text-input id="ssh_port" class="mt-1 block w-full" wire:model.live="form.ssh_port" placeholder="22" />
                        <x-input-error :messages="$errors->get('form.ssh_port')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ftp_username" value="Username" />
                        <x-text-input id="ftp_username" class="mt-1 block w-full" wire:model.live="form.username" />
                        <x-input-error :messages="$errors->get('form.username')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ftp_password" value="{{ $editingId ? 'Password (leave blank to keep)' : 'Password' }}" />
                        <x-text-input id="ftp_password" class="mt-1 block w-full" wire:model.live="form.password" type="password" />
                        <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ftp_root" value="Default Remote Root Path (optional)" />
                        <x-text-input id="ftp_root" class="mt-1 block w-full" wire:model.live="form.root_path" placeholder="/public_html" />
                        <x-input-error :messages="$errors->get('form.root_path')" class="mt-2" />
                    </div>
                </div>

                <div class="border-t border-slate-200/70 dark:border-slate-800 pt-4 space-y-3">
                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">SSH Overrides (optional)</div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">SSH uses the same host/username/password as FTP by default. Only fill these if SSH needs different helpers or keys.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="ssh_pass_binary" value="SSH Pass Binary (optional)" />
                            <x-text-input id="ssh_pass_binary" class="mt-1 block w-full" wire:model.live="form.ssh_pass_binary" placeholder="/usr/bin/sshpass" />
                            <x-input-error :messages="$errors->get('form.ssh_pass_binary')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="ssh_key_path" value="SSH Key Path (optional)" />
                            <x-text-input id="ssh_key_path" class="mt-1 block w-full" wire:model.live="form.ssh_key_path" placeholder="/home/user/.ssh/id_rsa" />
                            <x-input-error :messages="$errors->get('form.ssh_key_path')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.ssl" />
                        Use FTPS (SSL)
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.passive" />
                        Passive mode
                    </label>
                    <div>
                        <x-input-label for="ftp_timeout" value="Timeout (seconds)" />
                        <x-text-input id="ftp_timeout" class="mt-1 block w-full" wire:model.live="form.timeout" />
                        <x-input-error :messages="$errors->get('form.timeout')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="save" class="px-4 py-2 rounded-md bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900">
                        {{ $editingId ? 'Update Account' : 'Create Account' }}
                    </button>
                    <button type="button" wire:click="testConnection" class="px-4 py-2 rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 inline-flex items-center">
                        <x-loading-spinner target="testConnection" />
                        Test FTP
                    </button>
                    <button type="button" wire:click="testSshConnection" class="px-4 py-2 rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 inline-flex items-center">
                        <x-loading-spinner target="testSshConnection" />
                        Test SSH
                    </button>
                    @if ($editingId)
                        <button type="button" wire:click="delete({{ $editingId }})" onclick="return confirm('Delete this FTP/SSH access record?') || event.stopImmediatePropagation()" class="px-4 py-2 rounded-md border border-rose-300 text-rose-600 hover:text-rose-700 dark:border-rose-600/60 dark:text-rose-300">
                            Delete
                        </button>
                    @endif
                    @if ($testStatus)
                        @php
                            $ftpClass = match ($testStatus) {
                                'ok' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
                                'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
                                default => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
                            };
                        @endphp
                        <span class="px-2 py-1 rounded-full text-xs uppercase tracking-wide {{ $ftpClass }}">{{ $testStatus }}</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $testMessage }}</span>
                    @endif
                    @if ($sshTestStatus)
                        @php
                            $sshClass = match ($sshTestStatus) {
                                'ok' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
                                'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
                                default => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
                            };
                        @endphp
                        <span class="px-2 py-1 rounded-full text-xs uppercase tracking-wide {{ $sshClass }}">ssh {{ $sshTestStatus }}</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $sshTestMessage }}</span>
                    @endif
                </div>
            </div>
        @endif

        @if ($tab === 'list')
            <div class="space-y-4">
                @forelse ($accounts as $account)
                    <button type="button" wire:click="edit({{ $account->id }})" class="w-full text-left rounded-lg border border-slate-200/70 bg-white dark:bg-slate-900 dark:border-slate-800 p-4 transition hover:border-indigo-300 hover:shadow-sm dark:hover:border-indigo-500/60">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $account->name }}</h4>
                                    @php
                                        $ftpNeedsTest = $account->ftpNeedsTest();
                                        $sshNeedsTest = $account->sshNeedsTest();
                                    @endphp
                                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $account->ssl ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' }}">
                                        {{ $account->ssl ? 'FTPS' : 'FTP' }}
                                    </span>
                                    @if ($account->passive)
                                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Passive</span>
                                    @endif
                                    @if ($ftpNeedsTest)
                                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">FTP Needs Test</span>
                                    @elseif (in_array($account->ftp_test_status, ['error', 'warning'], true))
                                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">FTP Issue</span>
                                    @endif
                                    @if ($sshNeedsTest)
                                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">SSH Needs Test</span>
                                    @elseif (in_array($account->ssh_test_status, ['error', 'warning'], true))
                                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">SSH Issue</span>
                                    @endif
                                </div>
                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $account->host }}:{{ $account->port }}</p>
                                @if ($account->root_path)
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Root: {{ $account->root_path }}</p>
                                @endif
                                @if ($account->ssh_port && $account->ssh_port !== 22)
                                    <p class="text-xs text-slate-500 dark:text-slate-400">SSH Port: {{ $account->ssh_port }}</p>
                                @endif
                            </div>
                            <div class="text-xs text-slate-400 dark:text-slate-500">
                                <svg width="32px" height="32px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path d="M6 12H18M18 12L13 7M18 12L13 17" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="--darkreader-inline-stroke: var(--darkreader-text-ffffff, #e8e6e3);" data-darkreader-inline-stroke=""></path> </g></svg>
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="rounded-lg border border-dashed border-slate-300/70 dark:border-slate-700 p-6 text-sm text-slate-500 dark:text-slate-400">
                        No FTP/SSH access records yet.
                    </div>
                @endforelse
            </div>
        @endif
    </div>
</div>
