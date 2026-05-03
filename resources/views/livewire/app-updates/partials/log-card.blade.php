@php
    $update = $update ?? null;
    $showOutput = (bool) ($showOutput ?? false);
    $actionLabel = fn (?string $action): string => match ($action) {
        'self_update' => 'App Update',
        'force_update' => 'Force Update',
        'rollback' => 'Rollback',
        'app_dependency_audit' => 'App Dependency Audit',
        'app_composer_update' => 'App Composer Update',
        'app_npm_update' => 'App Npm Update',
        'app_npm_audit_fix' => 'App Npm Audit Fix',
        'app_npm_audit_fix_force' => 'App Npm Audit Fix (Force)',
        default => ucfirst(str_replace('_', ' ', (string) ($action ?: 'update'))),
    };
    $statusPill = fn (?string $status): string => match ($status) {
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'warning', 'blocked' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        'failed' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        'running' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
        default => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300',
    };
@endphp

@if (! $update)
    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No entries yet.</p>
@else
    <div class="mt-3 min-w-0 rounded-lg border border-slate-200/70 dark:border-slate-800 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $actionLabel($update->action ?? null) }}</div>
                <div class="text-xs text-slate-400 dark:text-slate-500">{{ \App\Support\DateFormatter::forUser($update->started_at, 'M j, Y g:i a', 'Queued') }}</div>
            </div>
            <span class="text-xs uppercase tracking-wide px-2 py-1 rounded-full {{ $statusPill($update->status ?? null) }}">
                {{ $update->status ?? 'unknown' }}
            </span>
        </div>

        <div class="mt-2 text-xs text-slate-400 dark:text-slate-500">
            {{ $update->from_hash ?? 'n/a' }} -> {{ $update->to_hash ?? 'n/a' }}
        </div>

        @php($logLength = (int) ($update->output_log_length ?? 0))
        @if ($showOutput)
            <div class="mt-3 max-h-80 overflow-auto rounded-lg border border-slate-200/70 bg-slate-950/70 dark:border-slate-800">
                <pre class="inline-block min-w-full p-4 text-xs text-slate-200 whitespace-pre font-mono leading-relaxed">{{ \App\Support\ConsoleOutput::withoutPhpWarnings($update->output_log_tail ?? null) ?: 'No output captured.' }}</pre>
            </div>
        @elseif ($logLength > 0)
            <div class="mt-3">
                <button type="button" wire:click="toggleUpdateLog({{ $update->id }})" class="text-xs text-indigo-600 dark:text-indigo-300">
                    {{ ($expandedUpdateId ?? null) === $update->id ? 'Hide log' : 'View log' }}
                </button>
            </div>
            @if (($expandedUpdateId ?? null) === $update->id)
                <div class="mt-2 max-h-80 overflow-auto rounded-lg border border-slate-200/70 bg-slate-50 dark:border-slate-800 dark:bg-slate-950/40">
                    <pre class="inline-block min-w-full p-3 text-xs text-slate-600 dark:text-slate-300 whitespace-pre font-mono leading-relaxed">{{ $expandedUpdateLog }}</pre>
                </div>
                @if ($expandedUpdateLogTruncated ?? false)
                    <div class="mt-2 text-[11px] text-amber-500 dark:text-amber-300">Showing the latest log tail.</div>
                @endif
            @endif
        @endif
    </div>
@endif
