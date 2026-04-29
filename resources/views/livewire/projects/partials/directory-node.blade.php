@php
    $directories = array_values($node['directories'] ?? []);
    $projectsInNode = $node['projects'] ?? [];
    $projectCount = (int) ($node['project_count'] ?? count($projectsInNode));
    $depth = (int) ($depth ?? 0);
    $issueCounts = $node['issue_counts'] ?? [];
    $folderPath = (string) ($node['path'] ?? $node['name'] ?? 'folder');
    $folderKey = 'gwm-project-folder:'.md5($folderPath);
@endphp

<details
    wire:key="project-folder-{{ md5($folderPath) }}"
    class="rounded-lg border border-slate-200/70 bg-slate-900/30 dark:border-slate-800 p-3"
    x-data="{
        key: @js($folderKey),
        open: false,
        init() {
            const stored = localStorage.getItem(this.key);
            this.open = stored === null ? false : stored === 'true';
            this.$el.open = this.open;
        },
    }"
    x-bind:open="open"
    @toggle="open = $el.open; localStorage.setItem(key, open ? 'true' : 'false')"
>
    <summary class="cursor-pointer list-none">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2 text-sm font-semibold text-slate-100">
                    <svg class="h-4 w-4 text-indigo-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M2 4.75A1.75 1.75 0 013.75 3h4.57c.464 0 .909.184 1.237.513l1.18 1.18c.328.328.773.512 1.237.512h4.269A1.75 1.75 0 0118 6.955v8.295A1.75 1.75 0 0116.25 17H3.75A1.75 1.75 0 012 15.25V4.75z" />
                    </svg>
                    {{ $node['name'] ?? 'Folder' }}
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if (($issueCounts['permissions'] ?? 0) > 0)
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            Permissions {{ $issueCounts['permissions'] }}
                        </span>
                    @endif
                    @if (($issueCounts['updates'] ?? 0) > 0)
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                            Updates {{ $issueCounts['updates'] }}
                        </span>
                    @endif
                    @if (($issueCounts['vulnerabilities'] ?? 0) > 0)
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                            Vulnerabilities {{ $issueCounts['vulnerabilities'] }}
                        </span>
                    @endif
                    @if (($issueCounts['composer'] ?? 0) > 0)
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            Composer {{ $issueCounts['composer'] }}
                        </span>
                    @endif
                    @if (($issueCounts['npm'] ?? 0) > 0)
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            Npm {{ $issueCounts['npm'] }}
                        </span>
                    @endif
                    @if (($issueCounts['ftp'] ?? 0) > 0)
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                            FTP {{ $issueCounts['ftp'] }}
                        </span>
                    @endif
                    @if (($issueCounts['ssh'] ?? 0) > 0)
                        <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                            SSH {{ $issueCounts['ssh'] }}
                        </span>
                    @endif
                </div>
            </div>
            <span class="text-xs text-slate-400">
                {{ $projectCount }} {{ $projectCount === 1 ? 'project' : 'projects' }}
            </span>
        </div>
    </summary>

    <div class="mt-3 space-y-3 border-l border-slate-800 pl-3">
        @foreach ($directories as $childNode)
            @include('livewire.projects.partials.directory-node', [
                'node' => $childNode,
                'depth' => $depth + 1,
                'queueProjects' => $queueProjects ?? [],
                'auditInProcess' => $auditInProcess ?? [],
                'buildInProcess' => $buildInProcess ?? [],
            ])
        @endforeach

        @foreach ($projectsInNode as $project)
            @include('livewire.projects.partials.project-card', [
                'project' => $project,
                'queueProjects' => $queueProjects ?? [],
                'auditInProcess' => $auditInProcess ?? [],
                'buildInProcess' => $buildInProcess ?? [],
            ])
        @endforeach
    </div>
</details>
