<form wire:submit.prevent="{{ $submitAction }}" class="mt-6 space-y-6">
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="name" value="Project Name" />
            <x-text-input id="name" class="mt-1 block w-full" wire:model.live="form.name" />
            <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="project_type" value="Project Type" />
            <select id="project_type" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.live="form.project_type">
                <option value="laravel">Laravel</option>
                <option value="node">Node</option>
                <option value="static">Static</option>
                <option value="custom">Custom</option>
            </select>
            <x-input-error :messages="$errors->get('form.project_type')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="repo_url" value="Repository URL" />
            <x-text-input id="repo_url" class="mt-1 block w-full" wire:model.live="form.repo_url" />
            <x-input-error :messages="$errors->get('form.repo_url')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="local_path" value="Local Path" />
            <x-text-input id="local_path" class="mt-1 block w-full" wire:model.live="form.local_path" placeholder="/home/user/testwebsite" />
            <x-input-error :messages="$errors->get('form.local_path')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="default_branch" value="Default Branch" />
            <x-text-input id="default_branch" class="mt-1 block w-full" wire:model.live="form.default_branch" />
            <x-input-error :messages="$errors->get('form.default_branch')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="health_url" value="Health Check URL" />
            <x-text-input id="health_url" class="mt-1 block w-full" wire:model.live="form.health_url" />
            @if (($form['project_type'] ?? 'custom') === 'laravel')
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Laravel apps often expose `/up`. Use a full URL or just `/up` to read `APP_URL` from the project.</p>
            @else
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Used for health checks. Provide a full URL or a path relative to the project base.</p>
            @endif
            <x-input-error :messages="$errors->get('form.health_url')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="build_command" value="Build Command" />
            <x-text-input id="build_command" class="mt-1 block w-full" wire:model.live="form.build_command" />
            <x-input-error :messages="$errors->get('form.build_command')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="test_command" value="Test Command" />
            <x-text-input id="test_command" class="mt-1 block w-full" wire:model.live="form.test_command" />
            <x-input-error :messages="$errors->get('form.test_command')" class="mt-2" />
        </div>
        <div class="sm:col-span-2">
            <x-input-label for="exclude_paths" value="Excluded Paths" />
            <textarea id="exclude_paths" rows="4" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.live="form.exclude_paths" placeholder="storage/app/uploads&#10;public/uploads&#10;cache/*"></textarea>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">One entry per line (or comma-separated). These paths are preserved during force deploy cleanup. The `storage` folder is always excluded.</p>
            <x-input-error :messages="$errors->get('form.exclude_paths')" class="mt-2" />
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.auto_deploy" />
            Auto deploy on update
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_composer_install" />
            Run composer install
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_npm_install" />
            Run npm install
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_build_command" />
            Run build command
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_test_command" />
            Run tests before deploy
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.allow_dependency_updates" />
            Allow dependency updates
        </label>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <x-primary-button>
            {{ $submitLabel }}
        </x-primary-button>
        @if (! empty($cancelUrl))
            <a href="{{ $cancelUrl }}" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                Cancel
            </a>
        @endif
    </div>
</form>

