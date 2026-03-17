<div class="py-10">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800">
            <div class="p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Add Project</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Use the wizard to configure a new project.</p>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full {{ $step === 1 ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-slate-200 dark:bg-slate-800' }}">1</span>
                        <span>Setup</span>
                        <span class="text-slate-300 dark:text-slate-700">→</span>
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full {{ $step === 2 ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-slate-200 dark:bg-slate-800' }}">2</span>
                        <span>Build & Deploy</span>
                    </div>
                </div>

                <form wire:submit.prevent="{{ $step === 2 ? 'save' : 'nextStep' }}" class="mt-6 space-y-6">
                    @php
                        $type = $form['project_type'] ?? 'custom';
                        $showComposer = in_array($type, ['laravel', 'custom'], true);
                        $showNpm = in_array($type, ['laravel', 'node', 'static', 'custom'], true);
                    @endphp
                    @if ($step === 1)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="name" value="Project Name" />
                                <x-text-input id="name" class="mt-1 block w-full" wire:model.live="form.name" />
                                <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="project_type" value="Project Type" />
                                <select id="project_type" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.live="form.project_type">
                                    @foreach ($projectTypes as $type)
                                        <option value="{{ $type['value'] }}">{{ $type['label'] }}</option>
                                    @endforeach
                                </select>
                                @php($typeMeta = collect($projectTypes)->firstWhere('value', $form['project_type'] ?? 'custom'))
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                                    {{ $typeMeta['description'] ?? '' }}
                                </p>
                                <x-input-error :messages="$errors->get('form.project_type')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="repo_url" value="Repository URL" />
                                <x-text-input id="repo_url" class="mt-1 block w-full" wire:model.live="form.repo_url" />
                                <x-input-error :messages="$errors->get('form.repo_url')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="default_branch" value="Default Branch" />
                                <x-text-input id="default_branch" class="mt-1 block w-full" wire:model.live="form.default_branch" />
                                <x-input-error :messages="$errors->get('form.default_branch')" class="mt-2" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="local_path" value="Local Path" />
                                <x-text-input id="local_path" class="mt-1 block w-full" wire:model.live="form.local_path" placeholder="/home/user/testwebsite" />
                                <x-input-error :messages="$errors->get('form.local_path')" class="mt-2" />
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
                            <div class="sm:col-span-2">
                                <x-input-label for="exclude_paths" value="Excluded Paths" />
                                <textarea id="exclude_paths" rows="4" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" wire:model.live="form.exclude_paths" placeholder="storage/app/uploads&#10;public/uploads&#10;cache/*"></textarea>
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">One entry per line (or comma-separated). These paths are preserved during force deploy cleanup. The `storage` folder is always excluded.</p>
                                <x-input-error :messages="$errors->get('form.exclude_paths')" class="mt-2" />
                            </div>
                        </div>
                    @endif

                    @if ($step === 2)
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.auto_deploy" />
                                Auto deploy on update
                            </label>
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.allow_dependency_updates" />
                                Allow dependency updates
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            @if ($showComposer)
                                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Composer</div>
                                    <label class="mt-3 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                        <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_composer_install" />
                                        Run composer install
                                    </label>
                                </div>
                            @endif

                            @if ($showNpm)
                                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Npm</div>
                                    <label class="mt-3 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                        <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_npm_install" />
                                        Run npm install
                                    </label>
                                </div>
                            @endif
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                    <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_build_command" />
                                    Run build command
                                </label>
                                <x-text-input id="build_command" class="mt-2 block w-full" wire:model.live="form.build_command" placeholder="npm run build" />
                                <x-input-error :messages="$errors->get('form.build_command')" class="mt-2" />
                            </div>
                            <div>
                                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                    <input type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="form.run_test_command" />
                                    Run tests before deploy
                                </label>
                                <x-text-input id="test_command" class="mt-2 block w-full" wire:model.live="form.test_command" placeholder="php artisan test" />
                                <x-input-error :messages="$errors->get('form.test_command')" class="mt-2" />
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-3">
                        @if ($step === 1)
                            <x-primary-button>
                                Next
                            </x-primary-button>
                            <a href="{{ route('projects.index') }}" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                                Cancel
                            </a>
                        @else
                            <button type="button" wire:click="previousStep" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100">
                                Back
                            </button>
                            <x-primary-button>
                                Save Project
                            </x-primary-button>
                            <a href="{{ route('projects.index') }}" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                                Cancel
                            </a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
