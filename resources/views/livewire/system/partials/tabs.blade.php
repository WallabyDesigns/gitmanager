@php
    use App\Models\AppUpdate;
    use App\Models\AuditIssue;
    use App\Models\SecurityAlert;
    use App\Services\EditionService;
    use App\Services\LicenseService;
    use App\Services\SelfUpdateService;
    use App\Services\SettingsService;

    $userId = auth()->id();
    $securityCount = $userId
        ? SecurityAlert::query()
            ->where('state', 'open')
            ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
            ->count()
        : 0;
    $auditCount = $userId
        ? AuditIssue::query()
            ->where('status', 'open')
            ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
            ->count()
        : 0;

    $latestUpdate = AppUpdate::query()->orderByDesc('started_at')->first();
    $updateIssueCount = $latestUpdate && $latestUpdate->status === 'failed' ? 1 : 0;
    $openAlerts = $securityCount + $auditCount + $updateIssueCount;

    $checkUpdatesEnabled = (bool) app(SettingsService::class)->get('system.check_updates', true);
    $status = $checkUpdatesEnabled ? app(SelfUpdateService::class)->getUpdateStatus() : ['status' => 'disabled'];
    $updateAvailable = $checkUpdatesEnabled && ($status['status'] ?? '') === 'update-available';
    $isEnterprise = app(EditionService::class)->current() === EditionService::ENTERPRISE;
    $licenseState = app(LicenseService::class)->state();
    $licenseStatus = strtolower((string) ($licenseState['status'] ?? 'missing'));
    $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    $isLocalInstall = app()->environment(['local', 'testing'])
        || in_array($appHost, ['localhost', '127.0.0.1', '::1'], true)
        || ($appHost !== '' && (str_ends_with($appHost, '.local') || str_ends_with($appHost, '.test')));
    $showLocalLicenseBadge = $isLocalInstall && $licenseStatus !== 'valid';

    $navItem = 'group flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition';
    $activeNav = 'border-indigo-400/40 bg-indigo-500/20 text-indigo-100';
    $idleNav = 'border-transparent text-slate-300 hover:border-slate-700 hover:bg-slate-800/70 hover:text-white';
@endphp

<aside class="space-y-4">
    <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-slate-950/90 p-4 text-slate-200">
        <div>
            <div class="text-xs uppercase tracking-[0.16em] text-slate-400">System</div>
            <div class="mt-1 text-lg font-semibold text-white">Control Center</div>
            <div class="text-xs text-slate-400">Updates, security, settings, and platform services.</div>
        </div>

        <nav class="mt-4 space-y-1.5">
            <a href="{{ route('system.updates') }}" class="{{ $navItem }} {{ request()->routeIs('system.updates') ? $activeNav : $idleNav }}">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                <span class="inline-flex items-center gap-2">
                    App Updates
                    @if ($updateAvailable)
                        <span class="inline-flex items-center justify-center rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">New</span>
                    @endif
                </span>
            </a>

            @if ($isEnterprise)
                <a href="{{ route('system.support') }}" class="{{ $navItem }} {{ request()->routeIs('system.support') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                    </svg>
                    Enterprise Support
                </a>
            @else
                <button
                    type="button"
                    onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Support' } }));"
                    class="{{ $navItem }} {{ $idleNav }} w-full"
                >
                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                    </svg>

                    <span class="inline-flex items-center gap-1">
                        Enterprise Support
                        <svg class="h-3 w-3 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                    </span>
                </button>
            @endif

            <a href="{{ route('system.scheduler') }}" class="{{ $navItem }} {{ request()->routeIs('system.scheduler') ? $activeNav : $idleNav }}">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-list-end-icon lucide-list-end"><path d="M16 5H3"/><path d="M16 12H3"/><path d="M9 19H3"/><path d="m16 16-3 3 3 3"/><path d="M21 5v12a2 2 0 0 1-2 2h-6"/></svg>

                <span class="inline-flex items-center gap-2">
                    Scheduler & Queue
                    @if ($openAlerts > 0)
                        <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $openAlerts }}</span>
                    @endif
                </span>
            </a>

            <a href="{{ route('system.application') }}" class="{{ $navItem }} {{ request()->routeIs('system.application') ? $activeNav : $idleNav }}">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                <span class="inline-flex items-center gap-2">
                    App & Security
                </span>
            </a>

            <a href="{{ route('system.audits') }}" class="{{ $navItem }} {{ request()->routeIs('system.audits') ? $activeNav : $idleNav }}">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" /></svg>
                <span class="inline-flex items-center gap-2">
                    Audits & Alerts
                </span>
            </a>
            <a href="{{ route('system.licensing') }}" class="{{ $navItem }} {{ request()->routeIs('system.licensing') ? $activeNav : $idleNav }}">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-award-icon lucide-award"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>
                <span class="inline-flex items-center gap-2">
                    Edition & License
                    @if ($showLocalLicenseBadge)
                        <span class="inline-flex items-center justify-center rounded-full bg-amber-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">Fix</span>
                    @endif
                </span>
            </a>

            <a href="{{ route('system.environment') }}" class="{{ $navItem }} {{ request()->routeIs('system.environment') ? $activeNav : $idleNav }}">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" /></svg>
                Environment Config
            </a>

            <a href="{{ route('system.email') }}" class="{{ $navItem }} {{ request()->routeIs('system.email') ? $activeNav : $idleNav }}">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                Email Settings
            </a>

            @if ($isEnterprise)
                <a href="{{ route('system.white-label') }}" class="{{ $navItem }} {{ request()->routeIs('system.white-label') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                    White Label
                </a>
            @else
                <button
                    type="button"
                    onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'White Label Branding' } }));"
                    class="{{ $navItem }} {{ $idleNav }} w-full"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>

                    <span class="inline-flex items-center gap-1">
                        White Label
                        <svg class="h-3 w-3 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                    </span>
                </button>
            @endif
        </nav>
    </div>
</aside>
