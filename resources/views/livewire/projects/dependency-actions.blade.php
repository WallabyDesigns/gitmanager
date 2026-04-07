<div class="space-y-6">
    @php
        $actionDisabledClass = $permissionsLocked ? 'opacity-60 cursor-not-allowed' : '';
    @endphp

    <div>
        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Dependency Actions</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Run composer/npm actions on demand.</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @if ($hasComposer)
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Composer</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="composerInstall" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="composerInstall" />
                            Install
                        </button>
                        <button type="button" wire:click="composerUpdate" class="px-3 py-2 text-sm rounded-md border border-indigo-300 text-indigo-600 hover:text-indigo-800 dark:border-indigo-500/50 dark:text-indigo-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="composerUpdate" />
                            Update
                        </button>
                        <button type="button" wire:click="composerAudit" class="px-3 py-2 text-sm rounded-md border border-emerald-300 text-emerald-700 hover:text-emerald-800 dark:border-emerald-500/40 dark:text-emerald-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="composerAudit" />
                            Audit
                        </button>
                        <button type="button" wire:click="appClearCache" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="appClearCache" />
                            Clear Cache
                        </button>
                        @if ($hasLaravel)
                            <button type="button" wire:click="laravelMigrate" class="px-3 py-2 text-sm rounded-md border border-sky-300 text-sky-700 hover:text-sky-900 dark:border-sky-500/50 dark:text-sky-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                                <x-loading-spinner target="laravelMigrate" />
                                Migrate
                            </button>
                        @endif
                    </div>
                </div>
            @endif
            @if ($hasNpm)
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Npm</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="npmInstall" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="npmInstall" />
                            Install
                        </button>
                        <button type="button" wire:click="npmUpdate" class="px-3 py-2 text-sm rounded-md border border-indigo-300 text-indigo-600 hover:text-indigo-800 dark:border-indigo-500/50 dark:text-indigo-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="npmUpdate" />
                            Update
                        </button>
                        <button type="button" wire:click="npmAuditFix" class="px-3 py-2 text-sm rounded-md border border-emerald-300 text-emerald-700 hover:text-emerald-800 dark:border-emerald-500/40 dark:text-emerald-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="npmAuditFix" />
                            Audit Fix
                        </button>
                        <button type="button" wire:click="npmAuditFixForce" onclick="return confirm('Force audit fix can introduce breaking changes. Continue?') || event.stopImmediatePropagation()" class="px-3 py-2 text-sm rounded-md border border-rose-300 text-rose-600 hover:text-rose-700 dark:border-rose-600/60 dark:text-rose-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="npmAuditFixForce" />
                            Audit Fix (Force)
                        </button>
                    </div>
                </div>
            @endif
        </div>
        @if (! $hasComposer && ! $hasNpm)
            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No composer.json or package.json detected. Use manual commands below.</p>
        @endif
    </div>

    <div>
        <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Manual Command</h4>
        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center">
            <label class="sr-only" for="custom_command">Command</label>
            <input
                id="custom_command"
                type="text"
                class="w-full sm:flex-1 border-slate-300 bg-white text-slate-900 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 {{ $actionDisabledClass }}"
                wire:model.live="customCommand"
                placeholder="npm run build"
                {{ $permissionsLocked ? 'disabled' : '' }}
            />
            <button type="button" wire:click="runCustomCommand" class="px-3 py-2 text-sm rounded-md bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                <x-loading-spinner target="runCustomCommand" />
                Run Command
            </button>
        </div>
    </div>

    <div>
        <div class="flex items-center justify-between gap-3">
            <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Latest Output</h4>
            <button type="button" wire:click="clearLatestDependencyOutput" class="text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                Clear
            </button>
        </div>
        @php
            $latestDependencyOutput = $latestDependencyLog?->output_log;
        @endphp
        <pre
            class="mt-2 max-h-64 overflow-auto text-xs text-slate-600 dark:text-slate-300 whitespace-pre-wrap bg-slate-50 dark:bg-slate-950/40 rounded-lg p-3 border border-slate-200/70 dark:border-slate-800"
            x-data
            x-init="
                const el = $el;
                const scrollToBottom = () => { el.scrollTop = el.scrollHeight; };
                $nextTick(scrollToBottom);
                const observer = new MutationObserver(scrollToBottom);
                observer.observe(el, { childList: true, characterData: true, subtree: true });
                $cleanup(() => observer.disconnect());
            "
        >{{ $latestDependencyOutput ?? 'No output yet.' }}</pre>
    </div>

    <div>
        <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Dependency Logs</h4>
        <div class="mt-3 space-y-3">
            @forelse ($dependencyLogs as $deployment)
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            {{ ucfirst(str_replace('_', ' ', $deployment->action)) }}
                        </div>
                        @php($warn = $deployment->status === 'failed' && str_contains($deployment->output_log ?? '', 'stashed changes could not be restored'))
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $warn ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : ($deployment->status === 'success' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($deployment->status === 'failed' ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300')) }}">
                            {{ $warn ? 'warning' : $deployment->status }}
                        </span>
                    </div>
                    <div class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                        {{ \App\Support\DateFormatter::forUser($deployment->started_at, 'M j, Y g:i a', 'Queued') }}
                    </div>
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
                            <summary class="cursor-pointer text-xs text-indigo-600 dark:text-indigo-300">View log</summary>
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
                <p class="text-sm text-slate-500 dark:text-slate-400">No dependency actions yet.</p>
            @endforelse
        </div>
    </div>

    <div x-data="{ open: @entangle('showPushModal').live }">
        <div
            x-show="open"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
            role="dialog"
            aria-modal="true"
            @keydown.escape.window="open = false"
            @click.self="open = false"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Push audit fix changes?</h3>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ $pushContext ?: 'Audit fix' }} updated dependency files. Commit and push these changes back to the repository.
                </p>
                @if ($pushAuditSummary)
                    <p class="mt-2 text-xs text-emerald-400">Audit summary: {{ $pushAuditSummary }}</p>
                @endif

                @if (! empty($pushFiles))
                    <div class="mt-4 rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/40 p-3 text-xs text-slate-600 dark:text-slate-300 overflow-x-auto">
                        @foreach ($pushFiles as $index => $file)
                            @if ($index < 12)
                                <div class="font-mono pl-1 whitespace-nowrap">{{ $file }}</div>
                            @endif
                        @endforeach
                        @if (count($pushFiles ?? []) > 12)
                            <div class="mt-2 text-slate-400 dark:text-slate-500">+ {{ count($pushFiles ?? []) - 12 }} more file(s)</div>
                        @endif
                    </div>
                @endif

                <div class="mt-4">
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Commit Message</label>
                    <input type="text" wire:model.live="pushCommitMessage" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                </div>

                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="closePushModal" @click="open = false" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100">
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
