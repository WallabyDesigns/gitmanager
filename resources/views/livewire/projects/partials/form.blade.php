<form wire:submit.prevent="{{ $submitAction }}" class="mt-6 space-y-6">
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="name" :value="__('Project Name')" />
            <x-text-input id="name" class="mt-1 block w-full" wire:model.live="form.name" />
            <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="directory_path" :value="__('Project Directory (optional)')" />
            <x-text-input id="directory_path" class="mt-1 block w-full" wire:model.live="form.directory_path" placeholder="Clients/Acme/Website" />
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('Use nested folders to organize projects. Leave blank to keep this project at the root level.') }}</p>
            <x-input-error :messages="$errors->get('form.directory_path')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="project_type" :value="__('Project Type')" />
            <select id="project_type" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.live="form.project_type">
                @php
                    $options = $projectTypes ?? [
                        ['value' => 'laravel', 'label' => 'Laravel', 'locked' => false],
                        ['value' => 'node', 'label' => 'Node', 'locked' => false],
                        ['value' => 'static', 'label' => 'Static', 'locked' => false],
                        ['value' => 'nextjs', 'label' => 'Next.js', 'locked' => true, 'locked_message' => 'Next.js projects are available in Enterprise Edition.'],
                        ['value' => 'react', 'label' => 'React App', 'locked' => true, 'locked_message' => 'React App projects are available in Enterprise Edition.'],
                        ['value' => 'python', 'label' => 'Python', 'locked' => true],
                        ['value' => 'container', 'label' => 'Container', 'locked' => false],
                        ['value' => 'custom', 'label' => 'Custom', 'locked' => true, 'locked_message' => 'Custom projects are available in Enterprise Edition.'],
                    ];
                    $selected = collect($options)->firstWhere('value', $form['project_type'] ?? 'custom');
                @endphp
                @foreach ($options as $option)
                    <option value="{{ $option['value'] }}">
                        {{ $option['label'] }}{{ ! empty($option['locked']) ? ' 🔒' : '' }}
                    </option>
                @endforeach
            </select>
            @if (! empty($selected['locked']))
                <p class="mt-1 text-xs text-amber-500 dark:text-amber-300">
                    {{ $selected['locked_message'] ?? 'This project type is currently unavailable.' }}
                </p>
            @endif
            <x-input-error :messages="$errors->get('form.project_type')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="repo_url" :value="__('Repository URL')" />
            <x-text-input id="repo_url" class="mt-1 block w-full" wire:model.live="form.repo_url" />
            <x-input-error :messages="$errors->get('form.repo_url')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="site_url" :value="__('Site URL')" />
            <x-text-input id="site_url" class="mt-1 block w-full" wire:model.live="form.site_url" placeholder="https://example.com" />
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('Main domain for this project. Used for quick links and as the default health check when Health Check URL is blank.') }}</p>
            <x-input-error :messages="$errors->get('form.site_url')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="local_path" :value="__('Local Path')" />
            <x-text-input id="local_path" class="mt-1 block w-full" wire:model.live="form.local_path" placeholder="/home/user/testwebsite" />
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('For FTP-only deploys, this is the project directory under FTP Root Path. Builds run in a managed local workspace and sync to that remote directory.') }}</p>
            @if ($localPathUsageWarning ?? null)
                <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    {{ $localPathUsageWarning }}
                </div>
            @endif
            <x-input-error :messages="$errors->get('form.local_path')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="default_branch" :value="__('Default Branch')" />
            <x-text-input id="default_branch" class="mt-1 block w-full" wire:model.live="form.default_branch" />
            <x-input-error :messages="$errors->get('form.default_branch')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="health_url" :value="__('Health Check URL')" />
            <x-text-input id="health_url" class="mt-1 block w-full" wire:model.live="form.health_url" />
            @if (($form['project_type'] ?? 'custom') === 'laravel')
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('Laravel apps often expose /up. Use a full URL or just /up to read APP_URL from the project, or leave blank to use the Site URL (defaults to /up).') }}</p>
            @else
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('Used for health checks. Provide a full URL or a path relative to the project base, or leave blank to use the Site URL.') }}</p>
            @endif
            <x-input-error :messages="$errors->get('form.health_url')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="build_command" :value="__('Build Command')" />
            <x-text-input id="build_command" class="mt-1 block w-full" wire:model.live="form.build_command" />
            <x-input-error :messages="$errors->get('form.build_command')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="test_command" :value="__('Test Command')" />
            <x-text-input id="test_command" class="mt-1 block w-full" wire:model.live="form.test_command" />
            <x-input-error :messages="$errors->get('form.test_command')" class="mt-2" />
        </div>
        <div class="sm:col-span-2">
            <x-input-label for="exclude_paths" :value="__('Excluded Paths')" />
            <textarea id="exclude_paths" rows="4" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.live="form.exclude_paths" placeholder="storage/app/uploads&#10;public/uploads&#10;cache/*"></textarea>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('One entry per line. These paths are preserved during force deploy cleanup. The storage folder is always excluded.') }}</p>
            <x-input-error :messages="$errors->get('form.exclude_paths')" class="mt-2" />
        </div>
        <div class="sm:col-span-2">
            <x-input-label for="whitelist_paths" :value="__('Whitelist Paths')" />
            <textarea id="whitelist_paths" rows="4" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.live="form.whitelist_paths" placeholder="public/build&#10;public/assets&#10;index.php"></textarea>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('One entry per line. If empty, all project paths are allowed. When set, FTPS deploy sync only uploads matching files/directories.') }}</p>
            <x-input-error :messages="$errors->get('form.whitelist_paths')" class="mt-2" />
        </div>
        <div class="sm:col-span-2 grid gap-4">
            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Environment (.env)') }}</div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Paste an optional .env file to seed on the next deployment. The file is only created when missing, so existing configs are preserved.') }}</p>
                <textarea rows="8" class="mt-3 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.defer="form.env_content" placeholder="APP_ENV=production&#10;APP_KEY=&#10;APP_DEBUG=false"></textarea>
                <x-input-error :messages="$errors->get('form.env_content')" class="mt-2" />
            </div>
            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('.htaccess') }}</div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Paste optional Apache rules to seed on the next deployment. Defaults only apply when the file is missing.') }}</p>
                <textarea rows="8" class="mt-3 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 font-mono text-xs" wire:model.defer="form.htaccess_content"></textarea>
                <x-input-error :messages="$errors->get('form.htaccess_content')" class="mt-2" />
            </div>
        </div>
        <div class="sm:col-span-2">
            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4 space-y-3">
                <div>
                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Remote Deployment (FTPS)') }}</div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Sync build output to a remote host via FTPS after local deploys. If SSH deployment is enabled, builds run on the remote host and FTPS sync is skipped.') }}</p>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.ftp_enabled" />
                    {{ __('Enable FTPS sync for this project') }}
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="ftp_account_id" :value="__('Remote Access')" />
                        <select id="ftp_account_id" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.live="form.ftp_account_id" {{ ($form['ftp_enabled'] ?? false) ? '' : 'disabled' }}>
                            <option value="">{{ __('Select access') }}</option>
                            @foreach ($ftpAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->host }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('form.ftp_account_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ftp_root_path" value="{{ __('Remote Root Path (optional)') }}" />
                        <x-text-input id="ftp_root_path" class="mt-1 block w-full" wire:model.live="form.ftp_root_path" placeholder="/public_html" {{ ($form['ftp_enabled'] ?? false) ? '' : 'disabled' }} />
                        <x-input-error :messages="$errors->get('form.ftp_root_path')" class="mt-2" />
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="testFtpConnection" class="px-3 py-2 text-xs rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100" {{ ($form['ftp_enabled'] ?? false) ? '' : 'disabled' }}>
                        {{ __('Test Connection') }}
                    </button>
                    @if ($ftpTestStatus ?? false)
                        @php
                            $ftpClass = match ($ftpTestStatus) {
                                'ok' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
                                'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
                                default => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
                            };
                        @endphp
                        <span class="px-2 py-1 rounded-full text-xs uppercase tracking-wide {{ $ftpClass }}">{{ $ftpTestStatus }}</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $ftpTestMessage }}</span>
                    @endif
                </div>
                @if (($form['ftp_enabled'] ?? false) && ! ($form['ssh_enabled'] ?? false) && in_array($form['project_type'] ?? 'laravel', ['laravel', '']))
                    <div class="rounded-md border border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                        <span class="font-semibold">{{ __('Storage link limitation:') }}</span>
                        {{ __('FTP does not support symlinks, so :link will be created as a plain directory on the remote rather than a symlink to :storage.', ['link' => '<code>public/storage</code>', 'storage' => '<code>storage/app/public</code>']) }}
                        {{ __('Files uploaded via :disk will not be publicly accessible unless you create the symlink manually.', ['disk' => '<code>Storage::disk(\'public\')</code>']) }}
                    </div>
                @endif
                <div class="border-t border-slate-200/70 dark:border-slate-800 pt-3 space-y-3">
                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Remote Deployment (SSH)') }}</div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Use the selected remote access credentials to run git + build steps on the remote host. When enabled, deployments run over SSH and local builds are skipped.') }}</p>
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.ssh_enabled" />
                        {{ __('Deploy over SSH (remote build)') }}
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="ssh_port" value="{{ __('SSH Port') }}" />
                            <x-text-input id="ssh_port" class="mt-1 block w-full" wire:model.live="form.ssh_port" placeholder="22" {{ ($form['ssh_enabled'] ?? false) ? '' : 'disabled' }} />
                            <x-input-error :messages="$errors->get('form.ssh_port')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="ssh_root_path" value="{{ __('SSH Root Path (optional)') }}" />
                            <x-text-input id="ssh_root_path" class="mt-1 block w-full" wire:model.live="form.ssh_root_path" placeholder="/home/user/public_html" {{ ($form['ssh_enabled'] ?? false) ? '' : 'disabled' }} />
                            <x-input-error :messages="$errors->get('form.ssh_root_path')" class="mt-2" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="ssh_commands" value="{{ __('SSH Commands (one per line)') }}" />
                        <textarea id="ssh_commands" rows="4" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.live="form.ssh_commands" placeholder="composer install --no-dev&#10;npm install&#10;npm run build" {{ ($form['ssh_enabled'] ?? false) ? '' : 'disabled' }}></textarea>
                        <x-input-error :messages="$errors->get('form.ssh_commands')" class="mt-2" />
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Password-based SSH uses a built-in askpass helper by default. You can optionally configure :sshpass per remote access record or via :passBinary; use a per-record key path or :keyPath for key-based auth.', ['sshpass' => '<code>sshpass</code>', 'passBinary' => '<code>GWM_SSH_PASS_BINARY</code>', 'keyPath' => '<code>GWM_SSH_KEY_PATH</code>']) }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.auto_deploy" />
            {{ __('Auto deploy on update') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_composer_install" />
            {{ __('Run composer install') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_npm_install" />
            {{ __('Run npm install') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_build_command" />
            {{ __('Run build command') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_test_command" />
            {{ __('Run tests before deploy') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.allow_dependency_updates" />
            {{ __('Allow dependency updates') }}
        </label>
    </div>

    <div class="rounded-lg border border-rose-200/80 dark:border-rose-500/40 bg-rose-50/70 dark:bg-rose-500/10 p-4">
        <div class="text-sm font-semibold text-rose-900 dark:text-rose-200">{{ __('Migration Safety Override') }}</div>
        <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">
            {{ __('If your database already has tables, running migrations may fail with "table already exists." Enable this to log a warning and continue deployments instead of failing the build.') }}
        </p>
        <label class="mt-3 flex items-center gap-2 text-sm text-rose-800 dark:text-rose-200">
            <input type="checkbox" class="rounded border-rose-300 text-rose-600 shadow-sm focus:ring-rose-500" wire:model.live="form.ignore_migration_table_exists" />
            {{ __('Ignore migration "table already exists" errors') }}
        </label>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <x-primary-button wire:loading.attr="disabled" wire:target="{{ $submitAction }}">
            {{ $submitLabel }}
            <x-loading-spinner target="{{ $submitAction }}" class="ml-2" />
        </x-primary-button>
        @if (! empty($cancelUrl))
            <a href="{{ $cancelUrl }}" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                {{ __('Cancel') }}
            </a>
        @endif
    </div>
</form>
