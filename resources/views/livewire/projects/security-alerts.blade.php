<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3 flex-wrap">
            <button type="button" wire:click="$set('tab', 'current')" class="px-3 py-2 text-sm rounded-md {{ $tab === 'current' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300' }}">
                Current Issues ({{ $openCount }})
            </button>
            <button type="button" wire:click="$set('tab', 'resolved')" class="px-3 py-2 text-sm rounded-md {{ $tab === 'resolved' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300' }}">
                Resolved Issues ({{ $resolvedCount }})
            </button>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="auditProject" wire:loading.attr="disabled" class="px-3 py-2 text-sm rounded-md border border-emerald-300 text-emerald-700 hover:text-emerald-800 dark:border-emerald-500/40 dark:text-emerald-300 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                <x-loading-spinner target="auditProject" />
                Audit Project
            </button>
            <button type="button" wire:click="sync" wire:loading.attr="disabled" class="px-3 py-2 text-sm rounded-md border border-indigo-300 text-indigo-600 hover:text-indigo-800 dark:border-indigo-500/50 dark:text-indigo-300 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                <x-loading-spinner target="sync" />
                Sync Alerts
            </button>
        </div>
    </div>

    @php
        $actionDisabledClass = ($permissionsLocked ?? false) ? 'opacity-60 cursor-not-allowed' : '';
    @endphp
    <div class="flex flex-wrap gap-2">
        @if ($hasComposer ?? false)
            <button type="button" wire:click="composerUpdate" class="px-3 py-2 text-xs rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 inline-flex items-center {{ $actionDisabledClass }}" {{ ($permissionsLocked ?? false) ? 'disabled' : '' }}>
                <x-loading-spinner target="composerUpdate" />
                Composer Update
            </button>
        @endif
        @if ($hasNpm ?? false)
            <button type="button" wire:click="npmAuditFix" class="px-3 py-2 text-xs rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 inline-flex items-center {{ $actionDisabledClass }}" {{ ($permissionsLocked ?? false) ? 'disabled' : '' }}>
                <x-loading-spinner target="npmAuditFix" />
                Npm Audit Fix
            </button>
            <button type="button" wire:click="npmAuditFixForce" onclick="return confirm('Force audit fix can introduce breaking changes. Continue?') || event.stopImmediatePropagation()" class="px-3 py-2 text-xs rounded-md border border-rose-300 text-rose-600 hover:text-rose-700 dark:border-rose-600/60 dark:text-rose-300 inline-flex items-center {{ $actionDisabledClass }}" {{ ($permissionsLocked ?? false) ? 'disabled' : '' }}>
                <x-loading-spinner target="npmAuditFixForce" />
                Npm Audit Fix (Force)
            </button>
        @endif
    </div>
    <p class="text-xs text-slate-500 dark:text-slate-400">
        Use the Dependency Actions tab to review logs or commit dependency changes after fixes.
    </p>

    @if (! $sslVerifyEnabled)
        <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-200">
            GitHub SSL verification is disabled. Sync runs without certificate validation.
        </div>
    @endif
    @if ($permissionsLocked ?? false)
        <div class="rounded-lg border border-amber-300/60 bg-amber-500/10 p-3 text-xs text-amber-200">
            Permissions need fixing before running security actions.
        </div>
    @endif

    <div>
        <div class="flex items-center justify-between gap-3">
            <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Latest Security Output</h4>
        </div>
        @php
            $latestSecurityOutput = $latestSecurityLog?->output_log;
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
                if (typeof $cleanup === 'function') {
                    $cleanup(() => observer.disconnect());
                }
            "
        >{{ $latestSecurityOutput ?? 'No output yet.' }}</pre>
    </div>

    <div>
        <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Security Logs</h4>
        <div class="mt-3 space-y-3">
            @forelse ($securityLogs as $deployment)
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            {{ ucfirst(str_replace('_', ' ', $deployment->action)) }}
                        </div>
                        @php($warn = $deployment->status === 'warning' || ($deployment->status === 'failed' && str_contains($deployment->output_log ?? '', 'stashed changes could not be restored')))
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $warn ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : ($deployment->status === 'success' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($deployment->status === 'failed' ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300')) }}">
                            {{ $warn ? 'warning' : $deployment->status }}
                        </span>
                    </div>
                    <div class="mt-2 text-xs text-slate-400 dark:text-slate-500">
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
                                key: 'gwm-security-log-{{ $deployment->id }}'
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
                <p class="text-sm text-slate-500 dark:text-slate-400">No security logs yet.</p>
            @endforelse
        </div>
    </div>

    <div class="space-y-6">
        <div class="space-y-3">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Audit Issues</h3>
            @forelse ($auditIssues as $issue)
                @php
                    $toolLabel = match ($issue->tool) {
                        'npm' => 'Npm audit',
                        'composer' => 'Composer audit',
                        default => ucfirst((string) ($issue->tool ?? 'audit')),
                    };
                @endphp
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $toolLabel }}</div>
                            <div class="text-xs text-slate-500 dark:text-slate-400">
                                Detected: {{ \App\Support\DateFormatter::forUser($issue->detected_at, 'M j, Y g:i a', 'Unknown') }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                {{ $issue->status }}
                            </span>
                            @if ($issue->severity)
                                <span class="px-2 py-1 rounded-full bg-rose-500/10 text-rose-300">
                                    {{ strtoupper($issue->severity) }}
                                </span>
                            @endif
                            @if ($issue->remaining_count !== null)
                                <span class="px-2 py-1 rounded-full bg-rose-500/10 text-rose-300">
                                    {{ $issue->remaining_count }} remaining
                                </span>
                            @endif
                        </div>
                    </div>
                    @if ($issue->summary)
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $issue->summary }}</p>
                    @endif
                    @if ($issue->status === 'resolved' && $issue->fix_summary)
                        <p class="mt-2 text-sm text-emerald-700 dark:text-emerald-300">{{ $issue->fix_summary }}</p>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-400 dark:text-slate-500">
                        @if ($issue->found_count !== null)
                            <span>Found: {{ $issue->found_count }}</span>
                        @endif
                        @if ($issue->fixed_count !== null)
                            <span>Fixed: {{ $issue->fixed_count }}</span>
                        @endif
                        @if ($issue->last_seen_at)
                            <span>Last seen: {{ \App\Support\DateFormatter::forUser($issue->last_seen_at, 'M j, Y g:i a') }}</span>
                        @endif
                        @if ($issue->resolved_at)
                            <span>Resolved: {{ \App\Support\DateFormatter::forUser($issue->resolved_at, 'M j, Y g:i a') }}</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500 dark:text-slate-400">No audit issues found for this tab.</p>
            @endforelse
        </div>

        <div class="space-y-3">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Dependabot Alerts</h3>
        @forelse ($alerts as $alert)
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                {{ $alert->package_name ?? 'Unknown package' }}
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400">
                                {{ $alert->ecosystem ?? 'unknown ecosystem' }}
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
                            <span>Created: {{ \App\Support\DateFormatter::forUser($alert->alert_created_at, 'M j, Y') }}</span>
                        @endif
                        @if ($alert->fixed_at)
                            <span>Fixed: {{ \App\Support\DateFormatter::forUser($alert->fixed_at, 'M j, Y') }}</span>
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
                <p class="text-sm text-slate-500 dark:text-slate-400">No Dependabot alerts found for this tab.</p>
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
        <div class="w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Push {{ $pushContext ?: 'Security fix' }} changes?</h3>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ $pushContext ?: 'Security fix' }} updated dependency files. Commit and push these changes back to the repository.
            </p>
            @if ($pushAuditSummary)
                <p class="mt-2 text-xs text-emerald-400">Audit summary: {{ $pushAuditSummary }}</p>
            @endif
            @if ($pushHasOtherChanges)
                <p class="mt-2 text-xs text-amber-400">Only dependency files will be committed. Other changes remain uncommitted.</p>
            @endif

            @if (! empty($pushFiles))
                <div class="mt-4 rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/40 px-4 py-3 text-xs text-slate-600 dark:text-slate-300 overflow-x-auto">
                    @foreach ($pushFiles as $index => $file)
                        @if ($index < 12)
                            <div class="font-mono whitespace-nowrap px-1">{{ $file }}</div>
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
