<div class="py-10" x-data="{ tab: 'overview', deleteOpen: false }" wire:init="refreshHealthStatus" wire:poll.60s="refreshHealthStatus">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @include('livewire.projects.partials.tabs')
        <div class="flex flex-wrap gap-2">
            <button type="button" @click="tab = 'overview'" :class="tab === 'overview' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                Overview
            </button>
            <button type="button" @click="tab = 'repository'" :class="tab === 'repository' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                Repository
            </button>
            <button type="button" @click="tab = 'dependencies'" :class="tab === 'dependencies' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                Dependency Actions
            </button>
            <button type="button" @click="tab = 'security'" :class="tab === 'security' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                <span class="flex items-center gap-2">
                    Security
                    @if (($securityOpenCount ?? 0) > 0)
                        <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-xs text-rose-200">{{ $securityOpenCount }}</span>
                    @endif
                </span>
            </button>
            @if ($envTabEnabled)
                <button type="button" @click="tab = 'env'" :class="tab === 'env' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                    Environment
                </button>
            @else
                <button type="button" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-400 dark:border-slate-700 dark:text-slate-500 opacity-60 cursor-not-allowed" disabled title="No .env or .env.example detected">
                    Environment
                </button>
            @endif
            <button type="button" @click="tab = 'debug'" :class="tab === 'debug' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                Debug
            </button>
        </div>

        @php
            $permissionsEnforced = ! $project->ftp_enabled && ! $project->ssh_enabled;
            $permissionsLocked = $permissionsEnforced && (bool) $project->permissions_locked;
            $actionDisabledClass = $permissionsLocked ? 'opacity-60 cursor-not-allowed' : '';
        @endphp
        @if ($permissionsLocked)
            <div class="rounded-xl border border-amber-300/60 p-4 text-sm text-amber-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-xs uppercase tracking-wide text-amber-300">Permissions need fixing</div>
                    <div class="text-sm text-amber-100">Deployments and dependency actions are paused until file permissions are writable.</div>
                    @if ($project->permissions_issue_message)
                        <div class="text-xs text-amber-200 mt-1">{{ $project->permissions_issue_message }}</div>
                    @endif
                    @if ($project->permissions_checked_at)
                        <div class="text-xs text-amber-300 mt-1">Last checked: {{ \App\Support\DateFormatter::forUser($project->permissions_checked_at, 'M j, Y g:i a') }}</div>
                    @endif
                </div>
                @if ($permissionsEnforced)
                    <button type="button" wire:click="fixPermissions" class="inline-flex items-center justify-center rounded-md border border-amber-300/60 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:border-white hover:text-white">
                        <x-loading-spinner target="fixPermissions" />
                        Run Fix Permissions
                    </button>
                @endif
            </div>
        @endif

        <div x-show="tab === 'overview'" x-cloak class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    @php
                        $healthStatus = $project->health_status ?? 'na';
                        $healthLabel = $healthStatus === 'ok' ? 'Health: OK' : 'Health: N/A';
                        $healthClass = $healthStatus === 'ok'
                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                            : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300';
                    @endphp
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $healthClass }}">
                        {{ $healthLabel }}
                    </span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Last checked: {{ \App\Support\DateFormatter::forUser($project->health_checked_at, 'M j, Y g:i a', 'Never') }}</span>
                    @if ($project->health_issue_message)
                        <span class="text-xs text-amber-700 dark:text-amber-300">
                            Laravel check: {{ $project->health_issue_message }}
                        </span>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="deploy" class="px-3 py-2 text-sm rounded-md bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                        <x-loading-spinner target="deploy" />
                        Deploy
                    </button>
                    @if ($permissionsLocked)
                        <button type="button" wire:click="deployAnyway" class="px-3 py-2 text-sm rounded-md border border-amber-300 text-amber-700 hover:text-amber-800 dark:border-amber-500/60 dark:text-amber-300 inline-flex items-center">
                            <x-loading-spinner target="deployAnyway" />
                            Attempt Staging Fix
                        </button>
                    @endif
                    <button type="button" wire:click="forceDeploy" onclick="return confirm('Force deploy will discard local changes. Continue?') || event.stopImmediatePropagation()" class="px-3 py-2 text-sm rounded-md border border-rose-300 text-rose-600 hover:text-rose-700 dark:border-rose-600/60 dark:text-rose-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                        <x-loading-spinner target="forceDeploy" />
                        Force Deploy
                    </button>
                    @if ($rollbackAvailable)
                        <button type="button" wire:click="rollback" onclick="return confirm('Rollback will redeploy the previous successful version. Continue?') || event.stopImmediatePropagation()" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="rollback" />
                            Undo Last Deploy
                        </button>
                    @endif
                    <button type="button" wire:click="checkHealth" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 inline-flex items-center">
                        <x-loading-spinner target="checkHealth" />
                        Health Check
                    </button>
                    @if ($permissionsEnforced)
                        <button type="button" wire:click="fixPermissions" class="px-3 py-2 text-sm rounded-md border border-amber-300 text-amber-700 hover:text-amber-800 dark:border-amber-500/60 dark:text-amber-300 inline-flex items-center">
                            <x-loading-spinner target="fixPermissions" />
                            Fix Permissions
                        </button>
                    @endif
                    <a href="{{ route('projects.edit', $project) }}" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100">
                        Edit
                    </a>
                    <button type="button" @click="deleteOpen = true" class="px-3 py-2 text-sm rounded-md border border-rose-300 text-rose-600 hover:text-rose-700 dark:border-rose-600/60 dark:text-rose-300">
                        Delete
                    </button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Branch</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $project->default_branch }}</div>
                </div>
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Last Deploy</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
                        @php
                            $lastDeploy = $project->last_deployed_at ?? ($lastSuccessfulDeploy?->started_at ?? null);
                        @endphp
                        {{ \App\Support\DateFormatter::forUser($lastDeploy, 'M j, Y g:i a', 'Never') }}
                    </div>
                    <div class="text-xs text-slate-400 dark:text-slate-500">
                        {{ $project->last_deployed_hash ?? ($lastSuccessfulDeploy?->to_hash ?? 'No hash yet') }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Auto Deploy</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {{ $project->auto_deploy ? 'Enabled' : 'Disabled' }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Tests</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {{ $project->run_test_command ? 'Enabled' : 'Disabled' }}
                    </div>
                    <div class="text-xs text-slate-400 dark:text-slate-500">{{ $project->test_command }}</div>
                </div>
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Last Checked</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {{ \App\Support\DateFormatter::forUser($project->last_checked_at, 'M j, Y g:i a', 'Never') }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Site URL</div>
                    @if ($project->site_url)
                        <a href="{{ $project->site_url }}" target="_blank" rel="noopener noreferrer" class="mt-1 text-sm font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-300 dark:hover:text-indigo-200 inline-flex break-all">
                            {{ $project->site_url }}
                        </a>
                    @else
                        <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">Not set</div>
                    @endif
                </div>
            </div>

            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Latest Debug Logs</h3>
                <div class="mt-3 space-y-3">
                    @forelse ($recentDebug as $deployment)
                        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ ucfirst(str_replace('_', ' ', $deployment->action)) }}
                                </div>
                                @php
                                    $warn = $deployment->status === 'warning'
                                        || ($deployment->status === 'failed' && str_contains($deployment->output_log ?? '', 'stashed changes could not be restored'));
                                @endphp
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
                                        key: 'gwm-recent-log-{{ $deployment->id }}'
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
                        <p class="text-sm text-slate-500 dark:text-slate-400">No logs yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div x-show="tab === 'repository'" x-cloak class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="checkUpdates" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 inline-flex items-center">
                    <x-loading-spinner target="checkUpdates" />
                    Check Updates
                </button>
                @if ($rollbackAvailable)
                    <button type="button" wire:click="rollback" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                        <x-loading-spinner target="rollback" />
                        Rollback
                    </button>
                @endif
            </div>

            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Recent Commits</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Last three commits on the default branch.</p>
                <div class="mt-4 space-y-3">
                    @forelse ($commits as $commit)
                        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                        {{ $commit['message'] }}
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ $commit['short'] }} · {{ $commit['author'] }} · {{ $commit['date'] }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if ($activeCommit && $commit['hash'] === $activeCommit)
                                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                            Active
                                        </span>
                                    @else
                                        <button type="button" wire:click="createPreviewForCommit('{{ $commit['hash'] }}')" class="px-3 py-2 text-sm rounded-md border border-indigo-300 text-indigo-600 hover:text-indigo-800 dark:border-indigo-500/50 dark:text-indigo-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                                            <x-loading-spinner target="createPreviewForCommit" />
                                            Create Preview
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500 dark:text-slate-400">No commits found yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Preview Build</h4>
                <p class="text-xs text-slate-500 dark:text-slate-400">Create a preview from any commit or branch.</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <x-text-input id="preview_commit" class="block w-full sm:max-w-md {{ $actionDisabledClass }}" wire:model.live="previewCommit" placeholder="origin/main or commit hash" {{ $permissionsLocked ? 'disabled' : '' }} />
                    <button type="button" wire:click="createPreview" class="px-3 py-2 text-sm rounded-md bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                        <x-loading-spinner target="createPreview" />
                        Create Preview
                    </button>
                </div>
            </div>
        </div>

        <div x-show="tab === 'dependencies'" x-cloak class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            @livewire('projects.dependency-actions', ['project' => $project], key('dep-actions-'.$project->id))
        </div>

        <div x-show="tab === 'security'" x-cloak class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            @livewire('projects.security-alerts', ['project' => $project], key('security-alerts-'.$project->id))
        </div>

        @if ($envTabEnabled)
            <div x-show="tab === 'env'" x-cloak class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
                @livewire('projects.env-editor', ['project' => $project], key('env-editor-'.$project->id))
            </div>
        @endif

        <div x-show="tab === 'debug'" x-cloak class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Debug Logs</h3>

            <div class="mt-4 space-y-4">
                @forelse ($deployments as $deployment)
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
                        @if ($deployment->from_hash || $deployment->to_hash)
                            <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                {{ $deployment->from_hash ?? 'n/a' }} → {{ $deployment->to_hash ?? 'n/a' }}
                            </div>
                        @endif
                        @if ($deployment->output_log)
                            <details
                                class="mt-3"
                                x-data="{
                                    open: false,
                                    key: 'gwm-debug-log-{{ $deployment->id }}'
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
                    <p class="text-sm text-slate-500 dark:text-slate-400">No deployments yet.</p>
                @endforelse
            </div>

            @if ($deployments->hasPages())
                <div class="mt-4">
                    {{ $deployments->links() }}
                </div>
            @endif
        </div>
    </div>

    <div
        x-show="deleteOpen"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
        role="dialog"
        aria-modal="true"
        @keydown.escape.window="deleteOpen = false"
        @click.self="deleteOpen = false"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Delete project?</h3>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Choose whether to delete just the project record or also remove its files from disk.
            </p>
            <div class="mt-6 flex flex-wrap justify-end gap-2">
                <button type="button" @click="deleteOpen = false" class="px-3 py-2 text-sm rounded-md border border-slate-300 text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-slate-100">
                    Cancel
                </button>
                <button type="button" wire:click="deleteProject" @click="deleteOpen = false" class="px-3 py-2 text-sm rounded-md border border-rose-300 text-rose-600 hover:text-rose-700 dark:border-rose-600/60 dark:text-rose-300">
                    Delete
                </button>
                <button type="button" wire:click="deleteProjectFiles" @click="deleteOpen = false" class="px-3 py-2 text-sm rounded-md border border-rose-400 text-rose-700 hover:text-rose-800 dark:border-rose-500/70 dark:text-rose-200">
                    Delete Files
                </button>
            </div>
        </div>
    </div>
</div>
