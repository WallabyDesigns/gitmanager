<div class="py-10" x-data="{ tab: 'overview', deleteOpen: false }" wire:poll.60s="$refresh">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.projects.partials.tabs')
            <div class="min-w-0 space-y-6">
                <div class="flex flex-wrap gap-2">
            <button type="button" @click="tab = 'overview'" :class="tab === 'overview' ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                {{ __('Overview') }}
            </button>
            <button type="button" @click="tab = 'repository'" :class="tab === 'repository' ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                {{ __('Repository') }}
            </button>
            <button type="button" @click="tab = 'dependencies'" :class="tab === 'dependencies' ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                {{ __('Dependency Actions') }}
            </button>
            <button type="button" @click="tab = 'security'" :class="tab === 'security' ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                <span class="flex items-center gap-2">
                    {{ __('Security') }}
                    @if (($securityOpenCount ?? 0) > 0)
                        <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-xs text-rose-200">{{ $securityOpenCount }}</span>
                    @endif
                    @if (($auditOpenCount ?? 0) > 0)
                        <span class="inline-flex items-center justify-center rounded-full border border-rose-500/40 bg-rose-500/10 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ __('Vulnerabilities found') }}</span>
                    @endif
                </span>
            </button>
            @if ($envTabEnabled)
                <button type="button" @click="tab = 'env'" :class="tab === 'env' ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                    {{ __('Environment') }}
                </button>
            @else
                <button type="button" class="px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-500 opacity-60 cursor-not-allowed" disabled title="No .env or .env.example detected">
                    {{ __('Environment') }}
                </button>
            @endif
            <button type="button" @click="tab = 'debug'" :class="tab === 'debug' ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-300'" class="px-3 py-2 text-sm rounded-md">
                {{ __('Debug') }}
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
                    <div class="text-xs uppercase tracking-wide text-amber-300">{{ __('Permissions need fixing') }}</div>
                    <div class="text-sm text-amber-100">{{ __('Deployments and dependency actions are paused until file permissions are writable.') }}</div>
                    @if ($project->permissions_issue_message)
                        <div class="text-xs text-amber-200 mt-1">{{ $project->permissions_issue_message }}</div>
                    @endif
                    @if ($project->permissions_checked_at)
                        <div class="text-xs text-amber-300 mt-1">{{ __('Last checked:') }} {{ \App\Support\DateFormatter::forUser($project->permissions_checked_at, 'M j, Y g:i a') }}</div>
                    @endif
                </div>
                @if ($permissionsEnforced)
                    <button type="button" wire:click="fixPermissions" class="inline-flex items-center justify-center rounded-md border border-amber-300/60 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:border-white hover:text-white">
                        <x-loading-spinner target="fixPermissions" />
                        {{ __('Run Fix Permissions') }}
                    </button>
                @endif
            </div>
        @endif

        <div x-show="tab === 'overview'" x-cloak class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    @php
                        $healthStatus = $project->health_status ?? 'na';
                        $healthLabel = $healthStatus === 'ok' ? 'Health: OK' : 'Health: N/A';
                        $healthClass = $healthStatus === 'ok'
                            ? 'bg-emerald-500/10 text-emerald-300'
                            : 'bg-slate-800 text-slate-300';
                    @endphp
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $healthClass }}">
                        {{ __($healthLabel) }}
                    </span>
                    @if ($composerIssue ?? false)
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-500/10 text-amber-300">
                            {{ __('Composer Issue') }}
                        </span>
                    @endif
                    @if ($npmIssue ?? false)
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-500/10 text-amber-300">
                            {{ __('NPM Issue') }}
                        </span>
                    @endif
                    <span class="text-xs text-slate-400">{{ __('Last checked:') }} {{ \App\Support\DateFormatter::forUser($project->health_checked_at, 'M j, Y g:i a', __('Never')) }}</span>
                    @if ($project->health_issue_message)
                        <span class="text-xs text-amber-300">
                            {{ __('Laravel check') }}: {{ $project->health_issue_message }}
                        </span>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="deploy" class="px-3 py-2 text-sm rounded-md hover:bg-slate-700 bg-slate-100 text-slate-900 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                        <x-loading-spinner target="deploy" />
                        {{ __('Deploy') }}
                    </button>
                    @if ($permissionsLocked)
                        <button type="button" wire:click="deployAnyway" class="px-3 py-2 text-sm rounded-md border hover:text-amber-800 border-amber-500/60 text-amber-300 inline-flex items-center">
                            <x-loading-spinner target="deployAnyway" />
                            {{ __('Attempt Staging Fix') }}
                        </button>
                    @endif
                    <button type="button" wire:click="forceDeploy" onclick="return confirm('{{ __('Force deploy will discard local changes. Continue?') }}') || event.stopImmediatePropagation()" class="px-3 py-2 text-sm rounded-md border hover:text-rose-700 border-rose-600/60 text-rose-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                        <x-loading-spinner target="forceDeploy" />
                        {{ __('Force Deploy') }}
                    </button>
                    @if ($rollbackAvailable)
                        <button type="button" wire:click="rollback" onclick="return confirm('{{ __('Rollback will redeploy the previous successful version. Continue?') }}') || event.stopImmediatePropagation()" class="px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-300 hover:text-slate-100 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                            <x-loading-spinner target="rollback" />
                            {{ __('Undo Last Deploy') }}
                        </button>
                    @endif
                    @if (($deploymentRunning ?? false))
                        <button type="button" wire:click="forceStopDeployment" onclick="return confirm('{{ __('Stop the running :task and clear queued items for this project?', ['task' => $runningTaskLabel ?? 'task']) }}') || event.stopImmediatePropagation()" class="px-3 py-2 text-sm rounded-md border hover:text-rose-800 border-rose-500/70 text-rose-200 inline-flex items-center">
                            <x-loading-spinner target="forceStopDeployment" />
                            {{ __('Stop :task', ['task' => $runningTaskLabel ?? 'Task']) }}
                        </button>
                    @endif
                    <button type="button" wire:click="checkHealth" class="px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-300 hover:text-slate-100 inline-flex items-center">
                        <x-loading-spinner target="checkHealth" />
                        {{ __('Health Check') }}
                    </button>
                    @if ($isEnterprise ?? false)
                        <button type="button" wire:click="auditProject" class="px-3 py-2 text-sm rounded-md border hover:text-emerald-800 border-emerald-500/40 text-emerald-300 inline-flex items-center">
                            <x-loading-spinner target="auditProject" />
                            {{ __('Audit Project') }}
                        </button>
                    @else
                        <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Automatic Project & Container Audits' } }));" class="px-3 py-2 text-sm rounded-md border hover:text-amber-800 border-amber-500/60 text-amber-300 inline-flex items-center gap-1.5">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd" />
                            </svg>
                            {{ __('Audit Project') }}
                        </button>
                    @endif
                    @if ($permissionsEnforced)
                        <button type="button" wire:click="fixPermissions" class="px-3 py-2 text-sm rounded-md border hover:text-amber-800 border-amber-500/60 text-amber-300 inline-flex items-center">
                            <x-loading-spinner target="fixPermissions" />
                            {{ __('Fix Permissions') }}
                        </button>
                    @endif
                    <a href="{{ route('projects.edit', $project) }}" class="px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-300 hover:text-slate-100">
                        {{ __('Edit') }}
                    </a>
                    <button type="button" @click="deleteOpen = true" class="px-3 py-2 text-sm rounded-md border hover:text-rose-700 border-rose-600/60 text-rose-300">
                        {{ __('Delete') }}
                    </button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-lg border border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('Branch') }}</div>
                    <div class="mt-1 text-sm font-semibold text-slate-100">{{ $project->default_branch }}</div>
                </div>
                <div class="rounded-lg border border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('Last Deploy') }}</div>
                    <div class="mt-1 text-sm font-semibold text-slate-100">
                        @php
                            $lastDeploy = $project->last_deployed_at ?? ($lastSuccessfulDeploy?->started_at ?? null);
                        @endphp
                        {{ \App\Support\DateFormatter::forUser($lastDeploy, 'M j, Y g:i a', __('Never')) }}
                    </div>
                    <div class="text-xs text-slate-500">
                        {{ $project->last_deployed_hash ?? ($lastSuccessfulDeploy?->to_hash ?? __('No hash yet')) }}
                    </div>
                    <div class="text-xs text-slate-500">
                        {{ __('Last checked: :date', ['date' => \App\Support\DateFormatter::forUser($project->updates_checked_at, 'M j, Y g:i a', __('Never'))]) }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('Auto Deploy') }}</div>
                    <div class="mt-1 text-sm font-semibold text-slate-100">
                        {{ $project->auto_deploy ? __('Enabled') : __('Disabled') }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('Tests') }}</div>
                    <div class="mt-1 text-sm font-semibold text-slate-100">
                        {{ $project->run_test_command ? __('Enabled') : __('Disabled') }}
                    </div>
                    <div class="text-xs text-slate-500">{{ $project->test_command }}</div>
                </div>
                <div class="rounded-lg border border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('Last Checked') }}</div>
                    <div class="mt-1 text-sm font-semibold text-slate-100">
                        {{ \App\Support\DateFormatter::forUser($project->last_checked_at, 'M j, Y g:i a', 'Never') }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-800 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('Site URL') }}</div>
                    @if ($project->site_url)
                        <a href="{{ $project->site_url }}" target="_blank" rel="noopener noreferrer" class="mt-1 text-sm font-semibold text-indigo-300 hover:text-indigo-200 inline-flex break-all">
                            {{ $project->site_url }}
                        </a>
                    @else
                        <div class="mt-1 text-sm font-semibold text-slate-100">{{ __('Not set') }}</div>
                    @endif
                </div>
            </div>

            @if($project->health_url != "")
                @php
                    $healthTotal = $healthHistory->count();
                    $healthPassed = $healthHistory->filter(fn ($entry) => data_get($entry, 'deployment_status') === 'success' || data_get($entry, 'status') === 'ok')->count();
                    $healthInconclusive = $healthHistory->filter(fn ($entry) => data_get($entry, 'deployment_status') === 'inconclusive')->count();
                    $healthConclusive = max(0, $healthTotal - $healthInconclusive);
                    $healthFailed = max(0, $healthConclusive - $healthPassed);
                    $healthPassRate = $healthConclusive > 0 ? round(($healthPassed / $healthConclusive) * 100) : null;
                @endphp
                <div class="rounded-lg border border-slate-800 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-100">{{ __('Health Check Window') }}</h3>
                            <p class="mt-1 text-xs text-slate-400">{{ __('Last :total of :limit scheduled checks.', ['total' => $healthTotal, 'limit' => \App\Models\Project::HEALTH_HISTORY_LIMIT]) }}</p>
                        </div>
                        @if($healthPassRate != null)
                            <div class="grid grid-cols-3 gap-2 text-center rounded-md px-3 py-2 bg-slate-950/60">
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('Pass') }}</div>
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('Fail') }}</div>
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('Rate') }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-100">{{ $healthPassed }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-100">{{ $healthFailed }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-100">{{ $healthPassRate.'%' }}</div>
                            </div>
                        @endif
                    </div>
                    <div class="mt-4 divide-y overflow-hidden rounded-md border divide-slate-800 border-slate-800">
                        @forelse ($healthHistory->take(5) as $entry)
                            @php
                                $entryOk = data_get($entry, 'deployment_status') === 'success' || data_get($entry, 'status') === 'ok';
                                $entryInconclusive = data_get($entry, 'deployment_status') === 'inconclusive';
                                $checkedAt = data_get($entry, 'checked_at');
                            @endphp
                            <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="h-2 w-2 shrink-0 rounded-full {{ $entryOk ? 'bg-emerald-400' : ($entryInconclusive ? 'bg-slate-400' : 'bg-rose-400') }}"></span>
                                    <span class="truncate text-slate-200">{{ data_get($entry, 'summary', $entryOk ? __('Health check passed.') : ($entryInconclusive ? __('Health check inconclusive.') : __('Health check failed.'))) }}</span>
                                </div>
                                <div class="flex shrink-0 items-center gap-3 text-slate-400">
                                    @if (data_get($entry, 'http_status'))
                                        <span>HTTP {{ data_get($entry, 'http_status') }}</span>
                                    @endif
                                    <span>{{ \App\Support\DateFormatter::forUser($checkedAt, 'M j, Y g:i a', 'Unknown') }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="px-3 py-4 text-sm text-slate-400">{{ __('No health checks recorded yet.') }}</div>
                        @endforelse
                    </div>
                </div>
            @endif

            <div>
                <h3 class="text-lg font-semibold text-slate-100">{{ __('Latest Debug Logs') }}</h3>
                <div class="mt-3 space-y-3">
                    @forelse ($recentDebug as $deployment)
                        <div class="min-w-0 rounded-lg border border-slate-800 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-sm font-semibold text-slate-100">
                                    {{ ucfirst(str_replace('_', ' ', $deployment->action)) }}
                                </div>
                                @php
                                    $warn = $deployment->status === 'warning'
                                        || ($deployment->status === 'failed' && str_contains($deployment->output_log ?? '', 'stashed changes could not be restored'));
                                @endphp
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
                                    {{ __('Environment warnings detected (PHP extensions). Audit results are still valid.') }}
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
<summary class="cursor-pointer text-xs text-indigo-300">{{ __('View Logs') }}</summary>
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
                        <p class="text-sm text-slate-400">{{ __('No logs yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div x-show="tab === 'repository'" x-cloak class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-6">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="checkUpdates" class="px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-300 hover:text-slate-100 inline-flex items-center">
                    <x-loading-spinner target="checkUpdates" />
                    {{ __('Check Updates') }}
                </button>
                @if ($isEnterprise ?? false)
                    <button type="button" wire:click="auditProject" class="px-3 py-2 text-sm rounded-md border hover:text-emerald-800 border-emerald-500/40 text-emerald-300 inline-flex items-center">
                        <x-loading-spinner target="auditProject" />
                        {{ __('Audit Project') }}
                    </button>
                @else
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Automatic Project & Container Audits' } }));" class="px-3 py-2 text-sm rounded-md border hover:text-amber-800 border-amber-500/60 text-amber-300 inline-flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd" />
                        </svg>
                        {{ __('Audit Project') }}
                    </button>
                @endif
                @if ($rollbackAvailable)
                    <button type="button" wire:click="rollback" class="px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-300 hover:text-slate-100 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                        <x-loading-spinner target="rollback" />
                        {{ __('Rollback') }}
                    </button>
                @endif
            </div>

            <div>
                <h3 class="text-lg font-semibold text-slate-100">{{ __('Recent Commits') }}</h3>
                <p class="text-sm text-slate-400">{{ __('Last three commits on the default branch.') }}</p>
                <div class="mt-4 space-y-3">
                    @forelse ($commits as $commit)
                        <div class="rounded-lg border border-slate-800 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="text-sm font-semibold text-slate-100">
                                        {{ $commit['message'] }}
                                    </div>
                                    <div class="text-xs text-slate-400">
                                        {{ $commit['short'] }} · {{ $commit['author'] }} · {{ $commit['date'] }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if ($activeCommit && $commit['hash'] === $activeCommit)
                                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-emerald-500/10 text-emerald-300">
                                            {{ __('Active') }}
                                        </span>
                                    @else
                                        <button type="button" wire:click="createPreviewForCommit('{{ $commit['hash'] }}')" class="px-3 py-2 text-sm rounded-md border hover:text-indigo-800 border-indigo-500/50 text-indigo-300 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                                            <x-loading-spinner target="createPreviewForCommit" />
                                            {{ __('Create Preview') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">{{ __('No commits found yet.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-slate-800 p-4">
                <h4 class="text-sm font-semibold text-slate-100">{{ __('Preview Build') }}</h4>
                <p class="text-xs text-slate-400">{{ __('Create a preview from any commit or branch.') }}</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <x-text-input id="preview_commit" class="block w-full sm:max-w-md {{ $actionDisabledClass }}" wire:model.live="previewCommit" placeholder="origin/main or commit hash" {{ $permissionsLocked ? 'disabled' : '' }} />
                    <button type="button" wire:click="createPreview" class="px-3 py-2 text-sm rounded-md hover:bg-slate-700 bg-slate-100 text-slate-900 {{ $actionDisabledClass }} inline-flex items-center" {{ $permissionsLocked ? 'disabled' : '' }}>
                        <x-loading-spinner target="createPreview" />
                        {{ __('Create Preview') }}
                    </button>
                </div>
            </div>
        </div>

        <div x-show="tab === 'dependencies'" x-cloak class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
            @livewire('projects.dependency-actions', ['project' => $project], key('dep-actions-'.$project->id))
        </div>

        <div x-show="tab === 'security'" x-cloak class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
            @livewire('projects.security-alerts', ['project' => $project], key('security-alerts-'.$project->id))
        </div>

        @if ($envTabEnabled)
            <div x-show="tab === 'env'" x-cloak class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
                @livewire('projects.env-editor', ['project' => $project], key('env-editor-'.$project->id))
            </div>
        @endif

        <div x-show="tab === 'debug'" x-cloak class="min-w-0 bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6">
            <h3 class="text-lg font-semibold text-slate-100">{{ __('Debug Logs') }}</h3>

            <div class="mt-4 space-y-4">
                @forelse ($deployments as $deployment)
                    <div class="min-w-0 rounded-lg border border-slate-800 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm font-semibold text-slate-100">
                                {{ ucfirst(str_replace('_', ' ', $deployment->action)) }}
                            </div>
                            @php($warn = $deployment->status === 'warning' || ($deployment->status === 'failed' && str_contains($deployment->output_log ?? '', 'stashed changes could not be restored')))
                            <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $warn ? 'bg-amber-500/10 text-amber-300' : ($deployment->status === 'success' ? 'bg-emerald-500/10 text-emerald-300' : ($deployment->status === 'failed' ? 'bg-rose-500/10 text-rose-300' : 'bg-slate-800 text-slate-300')) }}">
                                {{ __( $warn ? 'warning' : $deployment->status ) }}
                            </span>
                        </div>
                            <div class="mt-2 text-xs text-slate-500">
                                {{ \App\Support\DateFormatter::forUser($deployment->started_at, 'M j, Y g:i a', __('Queued')) }}
                            </div>
                            @php($hasEnvWarnings = $deployment->action === 'composer_audit' && $deployment->output_log && (str_contains($deployment->output_log, 'PHP Warning:') || str_contains($deployment->output_log, 'SourceGuardian requires')))
                            @if ($hasEnvWarnings)
                                <div class="mt-2 text-xs text-amber-500">
                                    {{ __('Environment warnings detected (PHP extensions). Audit results are still valid.') }}
                                </div>
                            @endif
                        @if ($deployment->from_hash || $deployment->to_hash)
                            <div class="mt-2 text-xs text-slate-400">
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
                    <p class="text-sm text-slate-400">{{ __('No deployments yet.') }}</p>
                @endforelse
            </div>

            @if ($deployments->hasPages())
                <div class="mt-4">
                    {{ $deployments->links() }}
                </div>
            @endif
                </div>
            </div>
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
        <div class="w-full max-w-md rounded-xl p-6 shadow-xl bg-slate-900 border border-slate-800">
            <h3 class="text-lg font-semibold text-slate-100">{{ __('Delete project?') }}</h3>
            <p class="mt-2 text-sm text-slate-400">
                {{ __('Choose whether to delete just the project record or also remove its files from disk.') }}
            </p>
            <div class="mt-6 flex flex-wrap justify-end gap-2">
                <button type="button" @click="deleteOpen = false" class="px-3 py-2 text-sm rounded-md border border-slate-700 text-slate-300 hover:text-slate-100">
                    {{ __('Cancel') }}
                </button>
                <button type="button" wire:click="deleteProject" @click="deleteOpen = false" class="px-3 py-2 text-sm rounded-md border hover:text-rose-700 border-rose-600/60 text-rose-300">
                    {{ __('Delete') }}
                </button>
                <button type="button" wire:click="deleteProjectFiles" @click="deleteOpen = false" class="px-3 py-2 text-sm rounded-md border hover:text-rose-800 border-rose-500/70 text-rose-200">
                    {{ __('Delete Files') }}
                </button>
            </div>
        </div>
    </div>
</div>
