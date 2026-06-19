<div class="min-w-0 space-y-6">
    @php
        $actionDisabledClass = $permissionsLocked ? 'gwm-action-disabled' : '';
    @endphp

    <div>
        <h3 class="text-lg font-semibold text-slate-100">{{ __('Dependency Actions') }}</h3>
        <p class="mt-1 text-sm text-slate-400">{{ __('Actions are based on the project type and can be customized below.') }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach ($actionGroups as $group => $actions)
                <div class="rounded-lg border border-slate-800 p-4">
                    <div class="text-sm font-semibold text-slate-100">{{ __($group) }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($actions as $key => $action)
                            <button
                                type="button"
                                wire:click="{{ $action['method'] }}"
                                @if ($action['destructive'] ?? false)
                                    onclick="return confirm('{{ __('Force audit fix can introduce breaking changes. Continue?') }}') || event.stopImmediatePropagation()"
                                @endif
                                class="px-3 py-2 text-sm rounded-md border {{ ($action['destructive'] ?? false) ? 'border-rose-600/60 text-rose-300 hover:text-rose-200' : 'border-slate-700 text-slate-300 hover:text-slate-100' }} {{ $actionDisabledClass }} inline-flex items-center"
                                {{ $permissionsLocked ? 'disabled' : '' }}
                            >
                                <x-loading-spinner :target="$action['method']" />
                                {{ __($action['label']) }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if (! empty($customActions))
                <div class="rounded-lg border border-slate-800 p-4">
                    <div class="text-sm font-semibold text-slate-100">{{ __('Custom') }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($customActions as $action)
                            <button type="button" wire:click="runSavedCustomAction('{{ $action['id'] }}')" class="px-3 py-2 text-sm rounded-md border border-indigo-500/50 text-indigo-300 hover:text-indigo-200 {{ $actionDisabledClass }} inline-flex items-center" title="{{ $action['command'] }}" {{ $permissionsLocked ? 'disabled' : '' }}>
                                <x-loading-spinner target="runSavedCustomAction('{{ $action['id'] }}')" />
                                {{ $action['name'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
        @if (empty($actionGroups) && empty($customActions))
            <p class="mt-3 text-sm text-slate-400">{{ __('No dependency actions are enabled for this project.') }}</p>
        @endif
    </div>

    <details class="rounded-lg border border-slate-800 p-4">
        <summary class="cursor-pointer text-sm font-semibold text-slate-100">{{ __('Configure Dependency Actions') }}</summary>
        <p class="mt-2 text-xs text-slate-400">{{ __('Defaults come from the project type. Select which built-in actions should appear in this tab.') }}</p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($actionDefinitions as $key => $action)
                <label class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" class="rounded border-slate-600 bg-slate-900 text-indigo-600 shadow-sm focus:ring-indigo-500" wire:model.live="enabledActions.{{ $key }}" />
                    {{ $action['group'] }}: {{ __($action['label']) }}
                </label>
            @endforeach
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" wire:click="saveActionSettings" class="px-3 py-2 text-sm rounded-md bg-indigo-600 text-white hover:bg-indigo-500">
                {{ __('Save Actions') }}
            </button>
            <button type="button" wire:click="resetActionSettings" class="px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-300 hover:text-slate-100">
                {{ __('Reset to Project Type Defaults') }}
            </button>
        </div>

        <div class="mt-6 border-t border-slate-800 pt-5">
            <div class="text-sm font-semibold text-slate-100">{{ __('Custom Dependency Actions') }}</div>
            <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,0.6fr)_minmax(0,1.4fr)_auto]">
                <div>
                    <label class="sr-only" for="custom_action_name">{{ __('Action Name') }}</label>
                    <input id="custom_action_name" type="text" wire:model="newCustomActionName" placeholder="{{ __('Action name') }}" class="w-full rounded-md border-slate-700 bg-slate-900 text-slate-100 focus:border-indigo-500 focus:ring-indigo-500" />
                    <x-input-error :messages="$errors->get('newCustomActionName')" class="mt-2" />
                </div>
                <div>
                    <label class="sr-only" for="custom_action_command">{{ __('Command') }}</label>
                    <input id="custom_action_command" type="text" wire:model="newCustomActionCommand" placeholder="php artisan queue:restart" class="w-full rounded-md border-slate-700 bg-slate-900 text-slate-100 focus:border-indigo-500 focus:ring-indigo-500" />
                    <x-input-error :messages="$errors->get('newCustomActionCommand')" class="mt-2" />
                </div>
                <button type="button" wire:click="addCustomAction" class="self-start px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-300 hover:text-slate-100">
                    {{ __('Add Action') }}
                </button>
            </div>

            @if (! empty($customActions))
                <div class="mt-4 space-y-2">
                    @foreach ($customActions as $action)
                        <div class="flex items-center justify-between gap-3 rounded-md border border-slate-800 bg-slate-950/30 px-3 py-2">
                            <div class="min-w-0">
                                <div class="text-sm text-slate-200">{{ $action['name'] }}</div>
                                <div class="truncate font-mono text-xs text-slate-500">{{ $action['command'] }}</div>
                            </div>
                            <button type="button" wire:click="removeCustomAction('{{ $action['id'] }}')" class="text-xs text-rose-300 hover:text-rose-200">
                                {{ __('Remove') }}
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </details>

    <div>
        <h4 class="text-sm font-semibold text-slate-100">{{ __('One-off Command') }}</h4>
        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center">
            <label class="sr-only" for="custom_command">{{ __('Command') }}</label>
            <input
                id="custom_command"
                type="text"
                class="w-full sm:flex-1 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm border-slate-700 bg-slate-900 text-slate-100 {{ $actionDisabledClass }}"
                wire:model="customCommand"
                placeholder="npm run build"
                {{ $permissionsLocked ? 'disabled' : '' }}
            />
            <button type="button" wire:click="runCustomCommand" class="px-3 py-2 text-sm rounded-md hover:bg-slate-700 bg-slate-100 text-slate-900 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                <x-loading-spinner target="runCustomCommand" />
                {{ __('Run Command') }}
            </button>
        </div>
    </div>

    <div class="min-w-0">
        <div class="flex items-center justify-between gap-3">
            <h4 class="text-sm font-semibold text-slate-100">{{ __('Latest Output') }}</h4>
            <button type="button" wire:click="clearLatestDependencyOutput" class="text-xs text-slate-400 hover:text-slate-200">
                {{ __('Clear') }}
            </button>
        </div>
        @php
            $latestDependencyOutput = $latestDependencyLog?->output_log;
        @endphp
        <div
            class="mt-2 w-full min-w-0 max-w-full max-h-64 overflow-auto rounded-lg border border-slate-800 bg-slate-950/40"
            style="scrollbar-gutter: stable;"
            x-data
            x-init="
                const el = $el;
                const scrollToBottom = () => { el.scrollTop = el.scrollHeight; };
                $nextTick(scrollToBottom);
                const observer = new MutationObserver(scrollToBottom);
                observer.observe(el, { childList: true, characterData: true, subtree: true });
                if (typeof $cleanup === 'function') {
                    $cleanup(() => observer.disconnect());
                }
            "
        >
            <pre class="inline-block min-w-full p-3 text-xs text-slate-300 whitespace-pre font-mono leading-relaxed align-top">{{ $latestDependencyOutput ?? __('No output yet.') }}</pre>
        </div>
    </div>

    <div>
        <h4 class="text-sm font-semibold text-slate-100">{{ __('Dependency Logs') }}</h4>
        <div class="mt-3 space-y-3">
            @forelse ($dependencyLogs as $deployment)
                <div class="min-w-0 rounded-lg border border-slate-800 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-sm font-semibold text-slate-100">
                            {{ ucfirst(str_replace('_', ' ', $deployment->action)) }}
                        </div>
                    @php($warn = $deployment->status === 'warning' || ($deployment->status === 'failed' && str_contains($deployment->output_log ?? '', 'stashed changes could not be restored')))
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $warn ? 'bg-amber-500/10 text-amber-300' : ($deployment->status === 'success' ? 'bg-emerald-500/10 text-emerald-300' : ($deployment->status === 'failed' ? 'bg-rose-500/10 text-rose-300' : 'bg-slate-800 text-slate-300')) }}">
                        {{ $warn ? 'warning' : $deployment->status }}
                    </span>
                    </div>
                    <div class="mt-2 text-xs text-slate-500">
                        {{ \App\Support\DateFormatter::forUser($deployment->started_at, 'M j, Y g:i a', 'Queued') }}
                    </div>
                    @php($hasEnvWarnings = $deployment->action === 'composer_audit' && $deployment->output_log && (str_contains($deployment->output_log, 'PHP Warning:') || str_contains($deployment->output_log, 'SourceGuardian requires')))
                    @if ($hasEnvWarnings)
                        <div class="mt-2 text-xs text-amber-500">
                            Environment warnings detected (PHP extensions). Audit results are still valid.
                        </div>
                    @endif
                    @if ($deployment->output_log)
                        <details
                            class="mt-3"
                            x-data="{
                                open: false,
                                key: 'gwm-dependency-log-{{ $deployment->id }}'
                            }"
                            x-init="
                                const stored = localStorage.getItem(key);
                                open = stored !== null ? stored === 'true' : open;
                                $el.open = open;
                            "
                            x-bind:open="open"
                            @toggle="open = $el.open; localStorage.setItem(key, open)"
                        >
                            <summary class="cursor-pointer text-xs text-indigo-300">{{ __('View log') }}</summary>
                            @include('livewire.projects.partials.grouped-log', [
                                'log' => $deployment->output_log,
                                'maxHeight' => 'max-h-80',
                                'autoScroll' => true,
                                'reverse' => false,
                                'placeholder' => 'No output yet.',
                            ])
                        </details>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-400">{{ __('No dependency actions yet.') }}</p>
            @endforelse
        </div>
    </div>

    <div x-data="{ open: @entangle('showPushModal') }">
        <div
            x-show="open"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
            role="dialog"
            aria-modal="true"
            @keydown.escape.window="open = false"
            @click.self="open = false"
        >
            <div class="w-full max-w-2xl rounded-xl p-6 shadow-xl bg-slate-900 border border-slate-800">
                <h3 class="text-lg font-semibold text-slate-100">{{ __('Push :context changes?', ['context' => $pushContext ?? __('Dependency Update')]) }}</h3>
                <p class="mt-2 text-sm text-slate-400">
                    {{ __(':context updated dependency files. Commit and push these changes back to the repository.', ['context' => $pushContext ?? __('Dependency Update')]) }}
                </p>
                @if ($pushAuditSummary)
                    <p class="mt-2 text-xs text-emerald-400">{{ __('Audit summary:') }} {{ $pushAuditSummary }}</p>
                @endif
                @if ($pushHasOtherChanges)
                    <p class="mt-2 text-xs text-amber-400">{{ __('Only dependency files will be committed. Other changes remain uncommitted.') }}</p>
                @endif

                @if (! empty($pushFiles))
                    <div class="mt-4 rounded-lg border border-slate-800 bg-slate-950/40 px-4 py-3 text-xs text-slate-300 overflow-x-auto">
                        @foreach ($pushFiles as $index => $file)
                            @if ($index < 12)
                                <div class="font-mono whitespace-nowrap px-1">{{ $file }}</div>
                            @endif
                        @endforeach
                        @if (count($pushFiles ?? []) > 12)
                            <div class="mt-2 text-slate-500">+ {{ count($pushFiles ?? []) - 12 }} more file(s)</div>
                        @endif
                    </div>
                @endif

                <div class="mt-4">
                    <label class="text-xs uppercase tracking-wide text-slate-500">{{ __('Commit Message') }}</label>
                    <input type="text" wire:model="pushCommitMessage" class="mt-2 w-full rounded-md border p-2 text-sm border-slate-700 bg-slate-950 text-slate-100" />
                </div>

                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="closePushModal" @click="open = false" class="px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-300 hover:text-slate-100">
                        Not Now
                    </button>
                    <button type="button" wire:click="commitAuditFix" class="px-3 py-2 text-sm rounded-md bg-emerald-600 text-white hover:bg-emerald-500">
                        Commit & Push
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
