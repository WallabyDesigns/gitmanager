@php
    use App\Models\AppUpdate;
    use App\Models\SecurityAlert;
    use App\Services\SelfUpdateService;

    $userId = auth()->id();
    $securityCount = $userId
        ? SecurityAlert::query()
            ->where('state', 'open')
            ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
            ->count()
        : 0;

    $latestUpdate = AppUpdate::query()->orderByDesc('started_at')->first();
    $updateIssueCount = $latestUpdate && $latestUpdate->status === 'failed' ? 1 : 0;
    $openAlerts = $securityCount + $updateIssueCount;

    $status = app(SelfUpdateService::class)->getUpdateStatus();
    $updateAvailable = ($status['status'] ?? '') === 'update-available';
@endphp

<div class="flex flex-wrap gap-2 border-b border-slate-200/70 dark:border-slate-800">
    <a href="{{ route('system.updates') }}"
       class="px-3 py-2 text-sm border-b-2 {{ request()->routeIs('system.updates') ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        <span class="flex items-center gap-2">
            App Updates
            @if ($updateAvailable)
                <span class="inline-flex items-center justify-center rounded-full bg-amber-400/20 px-2 py-0.5 text-xs text-amber-200">NEW</span>
            @endif
        </span>
    </a>
    <a href="{{ route('system.security') }}"
       class="px-3 py-2 text-sm border-b-2 {{ request()->routeIs('system.security') ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        <span class="flex items-center gap-2">
            Security
            @if ($openAlerts > 0)
                <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-xs text-rose-200">{{ $openAlerts }}</span>
            @endif
        </span>
    </a>
    <a href="{{ route('system.email') }}"
       class="px-3 py-2 text-sm border-b-2 {{ request()->routeIs('system.email') ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        Email Settings
    </a>
</div>
