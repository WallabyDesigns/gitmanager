@php
    $status = $project->health_status;
    $isOk = $status === 'ok';
    $isNa = $status === 'na';
    $history = $healthHistory[$project->id] ?? collect();
    $conclusiveHistory = $history->reject(fn ($check) => $check->status === 'inconclusive');
    $uptimePercent = $conclusiveHistory->count() > 0
        ? round($conclusiveHistory->where('status', 'success')->count() / $conclusiveHistory->count() * 100)
        : null;
@endphp

<a href="{{ route('projects.show', $project) }}" class="block rounded-lg border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 transition hover:border-indigo-300 dark:hover:border-indigo-500/60 hover:shadow-sm">
    <div class="flex items-center justify-between gap-3">
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                @if ($project->hasHealthMonitoring())
                    <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $isOk ? 'bg-emerald-500' : ($isNa ? 'bg-rose-500' : 'bg-slate-400') }}"></span>
                @else
                    <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-slate-500"></span>
                @endif
                <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $project->name }}</span>
            </div>
            @if ($project->site_url || $project->health_url)
                <p class="mt-0.5 ml-4 text-xs text-slate-400 dark:text-slate-500 truncate">{{ $project->health_url ?: $project->site_url }}</p>
            @elseif ($project->local_path)
                <p class="mt-0.5 ml-4 text-xs text-slate-400 dark:text-slate-500 truncate">{{ $project->local_path }}</p>
            @endif
        </div>
        <div class="shrink-0 flex items-center gap-4">
            @if ($history->count() > 0)
                <div class="hidden sm:flex items-end gap-0.5 h-5">
                    @foreach ($history->take(30) as $check)
                        <span class="w-1.5 rounded-sm {{ $check->status === 'success' ? 'bg-emerald-400 dark:bg-emerald-500' : ($check->status === 'inconclusive' ? 'bg-slate-300 dark:bg-slate-600' : 'bg-rose-400 dark:bg-rose-500') }}" style="height: {{ $check->status === 'success' ? '100%' : ($check->status === 'inconclusive' ? '35%' : '50%') }}"></span>
                    @endforeach
                </div>
                @if ($uptimePercent !== null)
                    <span class="text-xs font-medium {{ $uptimePercent >= 95 ? 'text-emerald-600 dark:text-emerald-400' : ($uptimePercent >= 75 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') }}">{{ $uptimePercent }}%</span>
                @endif
            @endif
            @if ($project->hasHealthMonitoring())
                <span class="text-xs font-semibold uppercase tracking-wide px-2 py-1 rounded-full {{ $isOk ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($isNa ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400') }}">
                    {{ $isOk ? __('OK') : ($isNa ? __('Down') : __('Unknown')) }}
                </span>
            @endif
        </div>
    </div>
    <div class="mt-2 ml-4 flex flex-wrap items-center gap-2 text-xs text-slate-400 dark:text-slate-500">
        @if ($project->health_checked_at)
            <span>{{ __('Last checked :time', ['time' => $project->health_checked_at->diffForHumans()]) }}</span>
        @endif
        @if ($project->updates_available)
            <span class="font-medium text-indigo-500 dark:text-indigo-300">{{ __('Updates') }}</span>
        @endif
        @if (($project->audit_open_count ?? 0) > 0)
            <span class="font-medium text-amber-500 dark:text-amber-300">{{ $project->audit_open_count }} {{ __( \Illuminate\Support\Str::plural('vulnerability', $project->audit_open_count) ) }}</span>
        @endif
    </div>
</a>
