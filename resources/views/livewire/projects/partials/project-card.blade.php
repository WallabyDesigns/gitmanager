<div class="group relative rounded-lg border bg-slate-900 border-slate-800 p-4 transition hover:shadow-sm hover:border-indigo-500/60">
    <a href="{{ route('projects.show', $project) }}" class="absolute inset-0 z-10 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400/60" aria-label="View {{ $project->name }} project"></a>
    <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h4 class="text-base font-semibold text-slate-100">{{ $project->name }}</h4>
                <x-loading-spinner target="checkAllHealth" size="w-3 h-3" />
                <x-loading-spinner target="checkAllUpdates" size="w-3 h-3" />
            </div>
            @php
                $hasHealthMonitoring = $project->hasHealthMonitoring();
                $healthStatus = $project->health_status ?? 'na';
                $healthLabel = $healthStatus === 'ok' ? 'Health: OK' : 'Health: N/A';
                $healthClass = $healthStatus === 'ok'
                    ? 'gwm-status-success'
                    : 'gwm-status-muted';
                $ftpNeedsTest = $project->ftp_enabled
                    && $project->ftpAccount
                    && $project->ftpAccount->ftpNeedsTest();
                $sshNeedsTest = $project->ssh_enabled
                    && $project->ftpAccount
                    && $project->ftpAccount->sshNeedsTest();
                $permissionsIssue = ! $project->ftp_enabled
                    && ! $project->ssh_enabled
                    && $project->permissions_locked;
                $ftpIssue = $project->ftp_enabled
                    && $project->ftpAccount
                    && in_array($project->ftpAccount->ftp_test_status, ['error', 'warning'], true)
                    && ! $ftpNeedsTest;
                $sshIssue = $project->ssh_enabled
                    && $project->ftpAccount
                    && in_array($project->ftpAccount->ssh_test_status, ['error', 'warning'], true)
                    && ! $sshNeedsTest;
                $composerIssue = in_array($project->last_composer_status ?? null, ['failed', 'warning'], true);
                $npmIssue = in_array($project->last_npm_status ?? null, ['failed', 'warning'], true);
            @endphp
            <div class="mt-2 flex flex-wrap items-center gap-2">
                @if ($hasHealthMonitoring)
                <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $healthClass }}">
                    {{ __( $healthLabel ) }}
                </span>
                @endif
                @if ($permissionsIssue)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-500/10 text-amber-300">
                        {{ __('Permissions') }}
                    </span>
                @endif
                @if ($project->updates_available)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-indigo-500/10 text-indigo-300">
                        {{ __('Updates Available') }}
                    </span>
                @endif
                @if (($project->audit_open_count ?? 0) > 0)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-500/10 text-rose-300">
                        {{ __('Vulnerabilities found') }}
                    </span>
                @endif
                @if ($composerIssue)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-500/10 text-amber-300">
                        {{ __('Composer Issue') }}
                    </span>
                @endif
                @if ($npmIssue)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-500/10 text-amber-300">
                        {{ __('Npm Issue') }}
                    </span>
                @endif
                @if ($project->ftp_enabled)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-blue-500/10 text-blue-300">
                        FTPS
                    </span>
                @endif
                @if ($ftpNeedsTest)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-slate-800 text-slate-300">
                        {{ __('FTP Needs Test') }}
                    </span>
                @endif
                @if ($sshNeedsTest)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-slate-800 text-slate-300">
                        {{ __('SSH Needs Test') }}
                    </span>
                @endif
                @if ($ftpIssue)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-500/10 text-rose-300">
                        FTP Issue
                        FTP {{ __('Issue') }}
                    </span>
                @endif
                @if ($sshIssue)
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-500/10 text-rose-300">
                        SSH {{ __('Issue') }}
                    </span>
                @endif
                @if (in_array($project->id, $queueProjects ?? [], true))
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-slate-800 text-slate-200">
                        {{ __('In Queue') }}
                    </span>
                @endif
                @if (in_array($project->id, $auditInProcess ?? [], true))
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-500/10 text-amber-300">
                        {{ __('Audit in process') }}
                    </span>
                @endif
                @if (in_array($project->id, $buildInProcess ?? [], true))
                    <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-indigo-500/10 text-indigo-300">
                        {{ __('Build in process') }}
                    </span>
                @endif
            </div>
            @if ($project->directory_path)
                <div class="mt-2 text-xs text-slate-500">
                    {{ __('Folder') }}: {{ $project->directory_path }}
                </div>
            @endif
            @if ($project->site_url)
                <a href="{{ $project->site_url }}" target="_blank" rel="noopener noreferrer" class="relative z-20 text-sm text-indigo-300 hover:text-indigo-200 break-all">
                    {{ $project->site_url }}
                </a>
            @else
                <p class="text-sm text-slate-400">{{ $project->local_path }}</p>
            @endif
            <div class="mt-2 text-xs text-slate-500">
                @php($lastDeploy = $project->last_deployed_at ?? ($project->last_successful_deploy_at ?? null))
                @php($lastChecked = $project->updates_checked_at)
                {{ __('Last deployed') }}: {{ \App\Support\DateFormatter::forUser($lastDeploy, 'M j, Y g:i a', __('Never')) }}.
                {{ __('Last checked') }}: {{ \App\Support\DateFormatter::forUser($lastChecked, 'M j, Y g:i a', __('Never')) }}
            </div>
            @if ($hasHealthMonitoring)
            <div class="mt-1 text-xs text-slate-500">
                {{ __('Last health check') }}: {{ \App\Support\DateFormatter::forUser($project->health_checked_at, 'M j, Y g:i a', __('Never')) }}
            </div>
            @endif
        </div>
            <div class="text-xs text-slate-500 hidden sm:block">
                <svg width="32px" height="32px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path d="M6 12H18M18 12L13 7M18 12L13 17" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path> </g></svg>
            </div>
    </div>
</div>
