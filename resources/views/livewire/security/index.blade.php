<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @if ($projectShell ?? false)
                @include('livewire.projects.partials.tabs', ['projectsTab' => 'action-center'])
            @else
                @include('livewire.system.partials.tabs', ['systemTab' => 'audits'])
            @endif
            <div class="space-y-6">
                @php
                    $hasDependencyProjects = ($dependencyProjects ?? collect())->isNotEmpty();
                @endphp
                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Current Issues</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                {{ $openCount }} actionable issue{{ $openCount === 1 ? '' : 's' }} across dependency checks, alerts, and audits.
                            </p>
                        </div>
                <div class="flex flex-wrap gap-2">
                    @if (($canAttemptResolution ?? false) && ($hasDependencyProjects || $alerts->isNotEmpty() || $auditIssues->isNotEmpty()))
                        <button type="button" wire:click="resolveAll" wire:loading.attr="disabled" class="px-3 py-2 text-sm rounded-md border border-emerald-300 text-emerald-700 hover:text-emerald-800 dark:border-emerald-500/50 dark:text-emerald-300 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                            <x-loading-spinner target="resolveAll" />
                            Attempt Resolve All
                        </button>
                        <button type="button" wire:click="resolveAllForce" wire:loading.attr="disabled" onclick="return confirm('Force fixes can introduce breaking dependency changes. Continue?') || event.stopImmediatePropagation()" class="px-3 py-2 text-sm rounded-md border border-rose-300 text-rose-700 hover:text-rose-800 dark:border-rose-500/50 dark:text-rose-300 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                            <x-loading-spinner target="resolveAllForce" />
                            Attempt Resolve All (Force)
                        </button>
                    @endif
                    @if ($canSyncAlerts ?? false)
                        <button type="button" wire:click="sync" wire:loading.attr="disabled" class="px-3 py-2 text-sm rounded-md border border-indigo-300 text-indigo-600 hover:text-indigo-800 dark:border-indigo-500/50 dark:text-indigo-300 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                            <x-loading-spinner target="sync" />
                            Sync Alerts
                        </button>
                    @endif
                </div>
                    </div>
                    @if (! $sslVerifyEnabled)
                        <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-200">
                            GitHub SSL verification is disabled. Sync runs without certificate validation.
                        </div>
                    @endif
                </div>

        @if ($appUpdateFailed && $latestUpdate)
            <div class="bg-rose-500/10 border border-rose-500/30 text-rose-200 rounded-xl p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">Git Web Manager update failed</h3>
                        <p class="text-sm text-rose-100/80">Last attempt: {{ \App\Support\DateFormatter::forUser($latestUpdate->finished_at, 'M j, Y g:i A', 'Unknown time') }}</p>
                    </div>
                    <a href="{{ route('system.updates') }}" class="px-4 py-2 rounded-md bg-rose-500/20 text-rose-100 text-sm hover:bg-rose-500/30">
                        View update logs
                    </a>
                </div>
            </div>
        @endif

                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6">
                    <div class="space-y-6">
                @if ($hasDependencyProjects)
                    <div class="space-y-4">
                        <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Dependency Issues</h4>
                        @foreach ($dependencyProjects as $project)
                            @php
                                $composerIssue = in_array($project->last_composer_status ?? null, ['failed', 'warning'], true);
                                $npmIssue = in_array($project->last_npm_status ?? null, ['failed', 'warning'], true);
                            @endphp
                            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                            <a href="{{ route('projects.show', $project) }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">{{ $project->name }}</a>
                                        </div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400">
                                            Recent dependency pipeline reported issues.
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2 text-xs">
                                        @if ($composerIssue)
                                            <span class="px-2 py-1 rounded-full bg-rose-500/10 text-rose-300">
                                                Composer {{ strtoupper((string) $project->last_composer_status) }}
                                            </span>
                                        @endif
                                        @if ($npmIssue)
                                            <span class="px-2 py-1 rounded-full bg-rose-500/10 text-rose-300">
                                                Npm {{ strtoupper((string) $project->last_npm_status) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                    <a href="{{ route('projects.show', $project) }}" class="text-indigo-600 dark:text-indigo-300">Open project</a>
                                    <button type="button" wire:click="resolveDependencyProject({{ $project->id }})" wire:loading.attr="disabled" class="text-emerald-600 hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-100 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                                        <x-loading-spinner target="resolveDependencyProject({{ $project->id }})" size="w-3 h-3" class="mr-1" />
                                        Attempt Fix
                                    </button>
                                    <button type="button" wire:click="resolveDependencyProjectForce({{ $project->id }})" wire:loading.attr="disabled" onclick="return confirm('Force fixes can introduce breaking dependency changes. Continue?') || event.stopImmediatePropagation()" class="text-rose-600 hover:text-rose-800 dark:text-rose-300 dark:hover:text-rose-100 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                                        <x-loading-spinner target="resolveDependencyProjectForce({{ $project->id }})" size="w-3 h-3" class="mr-1" />
                                        Attempt Fix (Force)
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($alerts->isNotEmpty())
                    <div class="space-y-4">
                        <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Dependabot Alerts</h4>
                        @foreach ($alerts as $alert)
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
                                        <span>Created: {{ \App\Support\DateFormatter::forUser($alert->alert_created_at, 'M j, Y') }}</span>
                                    @endif
                                    @if ($alert->fixed_at)
                                        <span>Fixed: {{ \App\Support\DateFormatter::forUser($alert->fixed_at, 'M j, Y') }}</span>
                                    @endif
                                </div>
                                <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                    <button type="button" wire:click="resolveSecurityAlert({{ $alert->id }})" wire:loading.attr="disabled" class="text-emerald-600 hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-100 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                                        <x-loading-spinner target="resolveSecurityAlert({{ $alert->id }})" size="w-3 h-3" class="mr-1" />
                                        Attempt Fix
                                    </button>
                                    <button type="button" wire:click="resolveSecurityAlertForce({{ $alert->id }})" wire:loading.attr="disabled" onclick="return confirm('Force fixes can introduce breaking dependency changes. Continue?') || event.stopImmediatePropagation()" class="text-rose-600 hover:text-rose-800 dark:text-rose-300 dark:hover:text-rose-100 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                                        <x-loading-spinner target="resolveSecurityAlertForce({{ $alert->id }})" size="w-3 h-3" class="mr-1" />
                                        Attempt Fix (Force)
                                    </button>
                                    <a href="{{ route('projects.show', $alert->project) }}" class="text-indigo-600 dark:text-indigo-300">Open project</a>
                                    @if ($alert->advisory_url)
                                        <a href="{{ $alert->advisory_url }}" target="_blank" class="text-indigo-600 dark:text-indigo-300">Advisory</a>
                                    @endif
                                    @if ($alert->html_url)
                                        <a href="{{ $alert->html_url }}" target="_blank" class="text-indigo-600 dark:text-indigo-300">Dependabot Report</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($auditIssues->isNotEmpty())
                    <div class="space-y-4">
                        <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Audit Issues</h4>
                        @foreach ($auditIssues as $issue)
                            <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                            {{ ucfirst($issue->tool) }} audit
                                        </div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ $issue->project?->name ?? 'Unknown project' }}
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
                                    </div>
                                </div>
                                @if ($issue->summary)
                                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $issue->summary }}</p>
                                @endif
                                @if ($issue->fix_summary && $issue->status === 'resolved')
                                    <p class="mt-2 text-sm text-emerald-600 dark:text-emerald-300">{{ $issue->fix_summary }}</p>
                                @endif
                                <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-400 dark:text-slate-500">
                                    @if ($issue->remaining_count !== null)
                                        <span>Remaining: {{ $issue->remaining_count }}</span>
                                    @endif
                                    @if ($issue->fixed_count !== null)
                                        <span>Fixed: {{ $issue->fixed_count }}</span>
                                    @endif
                                    @if ($issue->detected_at)
                                        <span>Detected: {{ \App\Support\DateFormatter::forUser($issue->detected_at, 'M j, Y') }}</span>
                                    @endif
                                    @if ($issue->resolved_at)
                                        <span>Resolved: {{ \App\Support\DateFormatter::forUser($issue->resolved_at, 'M j, Y') }}</span>
                                    @endif
                                </div>
                                <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                    <button type="button" wire:click="resolveAuditIssue({{ $issue->id }})" wire:loading.attr="disabled" class="text-emerald-600 hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-100 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                                        <x-loading-spinner target="resolveAuditIssue({{ $issue->id }})" size="w-3 h-3" class="mr-1" />
                                        Attempt Fix
                                    </button>
                                    <button type="button" wire:click="resolveAuditIssueForce({{ $issue->id }})" wire:loading.attr="disabled" onclick="return confirm('Force fixes can introduce breaking dependency changes. Continue?') || event.stopImmediatePropagation()" class="text-rose-600 hover:text-rose-800 dark:text-rose-300 dark:hover:text-rose-100 inline-flex items-center disabled:opacity-60 disabled:cursor-not-allowed">
                                        <x-loading-spinner target="resolveAuditIssueForce({{ $issue->id }})" size="w-3 h-3" class="mr-1" />
                                        Attempt Fix (Force)
                                    </button>
                                    @if ($issue->project)
                                        <a href="{{ route('projects.show', $issue->project) }}" class="text-indigo-600 dark:text-indigo-300">Open project</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (! $hasDependencyProjects && $alerts->isEmpty() && $auditIssues->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-slate-400">No current issues found.</p>
                @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
