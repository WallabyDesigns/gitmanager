@php
    use App\Services\NavigationStateService;

    $systemNavState = app(NavigationStateService::class)->systemSidebarState(auth()->user());
    $openAlerts = (int) ($systemNavState['openAlerts'] ?? 0);
    $updateAvailable = (bool) ($systemNavState['updateAvailable'] ?? false);
    $isEnterprise = (bool) ($systemNavState['isEnterprise'] ?? false);
    $showLocalLicenseBadge = (bool) ($systemNavState['showLocalLicenseBadge'] ?? false);

    $navItem = 'gwm-system-nav-item';
    $activeNav = 'gwm-system-nav-active';
    $idleNav = 'gwm-system-nav-idle';

    $currentSystemTab = $systemTab ?? match (true) {
        request()->routeIs('system.updates') => 'updates',
        request()->routeIs('system.support') => 'support',
        request()->routeIs('system.scheduler') => 'scheduler',
        request()->routeIs('system.application') => 'application',
        request()->routeIs('system.audits') => 'audits',
        request()->routeIs('system.diagnostics') => 'diagnostics',
        request()->routeIs('system.licensing') => 'licensing',
        request()->routeIs('system.environment') => 'environment',
        request()->routeIs('system.email') => 'email',
        request()->routeIs('system.white-label') => 'white-label',
        default => null,
    };
    $isSystemTab = fn (string $tab): bool => $currentSystemTab === $tab;

    $currentSystemPage = match (true) {
        $isSystemTab('updates') => 'App Updates',
        $isSystemTab('support') => 'Enterprise Support',
        $isSystemTab('scheduler') => 'Scheduler & Queue',
        $isSystemTab('application') => 'App & Security',
        $isSystemTab('audits') => 'Audits & Alerts',
        $isSystemTab('diagnostics') => 'Runtime Diagnostics',
        $isSystemTab('licensing') => 'Edition & License',
        $isSystemTab('environment') => 'Environment Config',
        $isSystemTab('email') => 'Email Settings',
        $isSystemTab('white-label') => 'White Label',
        default => 'System',
    };

    $searchIndex = [
        // App Updates
        ['label' => 'App Updates', 'section' => 'App Updates', 'url' => route('system.updates'), 'keys' => 'update upgrade download version release rollback channel stable beta automatic'],
        ['label' => 'Update Channel', 'section' => 'App Updates', 'url' => route('system.updates'), 'keys' => 'channel stable beta release version update'],
        ['label' => 'Auto-Update', 'section' => 'App Updates', 'url' => route('system.updates'), 'keys' => 'auto update automatic background'],
        ['label' => 'Rollback', 'section' => 'App Updates', 'url' => route('system.updates'), 'keys' => 'rollback revert undo restore previous version'],
        // Scheduler & Queue
        ['label' => 'Scheduler', 'section' => 'Scheduler & Queue', 'url' => route('system.scheduler'), 'keys' => 'scheduler cron schedule tasks frequency jobs'],
        ['label' => 'Queue Worker', 'section' => 'Scheduler & Queue', 'url' => route('system.scheduler'), 'keys' => 'queue worker jobs background processing horizon'],
        // App & Security
        ['label' => 'App URL / Domain', 'section' => 'App & Security', 'url' => route('system.application'), 'keys' => 'app url domain hostname address base'],
        ['label' => 'GitHub OAuth', 'section' => 'App & Security', 'url' => route('system.application'), 'keys' => 'github oauth login sso client id secret token'],
        ['label' => 'Cloudflare Turnstile', 'section' => 'App & Security', 'url' => route('system.application'), 'keys' => 'turnstile captcha cloudflare bot protection security'],
        ['label' => 'Force HTTPS / SSL', 'section' => 'App & Security', 'url' => route('system.application'), 'keys' => 'ssl https force secure certificate tls'],
        ['label' => 'Session Lifetime', 'section' => 'App & Security', 'url' => route('system.application'), 'keys' => 'session lifetime timeout expire login idle'],
        ['label' => 'Two-Factor Authentication', 'section' => 'App & Security', 'url' => route('system.application'), 'keys' => '2fa two factor authentication mfa totp authenticator'],
        // Audits & Alerts
        ['label' => 'Audit Log', 'section' => 'Audits & Alerts', 'url' => route('system.audits'), 'keys' => 'audit log history activity events'],
        ['label' => 'Webhooks', 'section' => 'Audits & Alerts', 'url' => route('system.audits'), 'keys' => 'webhook notification alert url endpoint http'],
        ['label' => 'Email Alerts', 'section' => 'Audits & Alerts', 'url' => route('system.audits'), 'keys' => 'email alert notification smtp push'],
        // Edition & License
        ['label' => 'License Key', 'section' => 'Edition & License', 'url' => route('system.licensing'), 'keys' => 'license key activate deactivate enterprise'],
        ['label' => 'Edition', 'section' => 'Edition & License', 'url' => route('system.licensing'), 'keys' => 'edition community enterprise license plan tier'],
        // Environment Config
        ['label' => 'Environment Variables', 'section' => 'Environment Config', 'url' => route('system.environment'), 'keys' => 'environment variables env file .env config'],
        ['label' => 'APP_KEY', 'section' => 'Environment Config', 'url' => route('system.environment'), 'keys' => 'app key encryption secret generate rotate'],
        ['label' => 'APP_DEBUG', 'section' => 'Environment Config', 'url' => route('system.environment'), 'keys' => 'app debug debug mode development production'],
        ['label' => 'Database Config', 'section' => 'Environment Config', 'url' => route('system.environment'), 'keys' => 'database db host port name username password mysql sqlite connection'],
        ['label' => 'Cache / Redis', 'section' => 'Environment Config', 'url' => route('system.environment'), 'keys' => 'cache redis driver store memcache'],
        // Email Settings
        ['label' => 'SMTP Settings', 'section' => 'Email Settings', 'url' => route('system.email'), 'keys' => 'smtp mail host server port username password tls ssl'],
        ['label' => 'Mail Driver', 'section' => 'Email Settings', 'url' => route('system.email'), 'keys' => 'mail driver mailgun sendgrid ses amazon smtp log mailer'],
        ['label' => 'From Name / Address', 'section' => 'Email Settings', 'url' => route('system.email'), 'keys' => 'from sender name email address reply-to'],
        ['label' => 'Test Email', 'section' => 'Email Settings', 'url' => route('system.email'), 'keys' => 'test email send verify check connection'],
        // Node.js
        ['label' => 'Node.js Runtime', 'section' => 'Node.js', 'url' => route('system.node'), 'keys' => 'node nodejs npm javascript runtime install version path'],
        ['label' => 'npm', 'section' => 'Node.js', 'url' => route('system.node'), 'keys' => 'npm node package manager install dependency'],
        // Runtime Diagnostics
        ['label' => 'PHP Version', 'section' => 'Runtime Diagnostics', 'url' => route('system.diagnostics'), 'keys' => 'php version runtime check status binary'],
        ['label' => 'Composer', 'section' => 'Runtime Diagnostics', 'url' => route('system.diagnostics'), 'keys' => 'composer php packages dependency manager install'],
        ['label' => 'Python / pip', 'section' => 'Runtime Diagnostics', 'url' => route('system.diagnostics'), 'keys' => 'python pip runtime tool install binary'],
        // White Label
        ['label' => 'Brand Name', 'section' => 'White Label', 'url' => route('system.white-label'), 'keys' => 'brand name company white label custom title'],
        ['label' => 'Logo & Favicon', 'section' => 'White Label', 'url' => route('system.white-label'), 'keys' => 'logo favicon icon image brand upload custom'],
        ['label' => 'Sub-heading', 'section' => 'White Label', 'url' => route('system.white-label'), 'keys' => 'sub heading subtitle tagline brand text'],
        ['label' => 'Hide Edition Label', 'section' => 'White Label', 'url' => route('system.white-label'), 'keys' => 'hide edition label community enterprise badge'],
        // Enterprise Support
        ['label' => 'Submit Support Ticket', 'section' => 'Enterprise Support', 'url' => route('system.support'), 'keys' => 'support ticket help enterprise contact bug'],
    ];
@endphp

<script data-navigate-once="true">
    window.__gwmSettingsIdx = @json($searchIndex);
</script>

<div x-data="{ open: false }" class="contents" @keydown.escape.window="open = false" x-on:livewire:navigating.window="open = false" x-effect="document.body.classList.toggle('overflow-hidden', open)">

    {{-- Mobile trigger (hidden on lg+) --}}
    <div class="lg:hidden">
        <button
            type="button"
            @click="open = true"
            class="flex w-full items-center justify-between gap-2 rounded-xl border border-slate-700 bg-slate-950/90 px-4 py-3 text-sm text-slate-200 hover:border-slate-600 hover:text-white transition"
        >
            <span class="flex items-center gap-2">
                <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                <span class="text-xs uppercase tracking-[0.16em] text-slate-400">{{ __('System') }}</span>
                <span class="font-medium text-white">{{ __($currentSystemPage) }}</span>
                @if ($openAlerts > 0)
                    <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $openAlerts }}</span>
                @endif
            </span>
            <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </button>
    </div>

    {{-- Desktop aside (hidden on mobile) --}}
    <aside class="hidden lg:block space-y-4" x-data="{
        search: '',
        get results() {
            const q = this.search.trim().toLowerCase();
            if (!q) return [];
            return (window.__gwmSettingsIdx || [])
                .filter(item => (item.label + ' ' + item.keys).toLowerCase().includes(q))
                .slice(0, 9);
        }
    }">
        <div class="rounded-xl border border-slate-800 bg-slate-950/90 p-4 text-slate-200">
            <div>
                <div class="text-xs uppercase tracking-[0.16em] text-slate-400">{{ __('System') }}</div>
                <div class="mt-1 text-lg font-semibold text-white">{{ __('Control Center') }}</div>
                <div class="text-xs text-slate-400">{{ __('Updates, security, settings, and platform services.') }}</div>
            </div>

            <div class="mt-3 relative" @click.outside="search = ''">
                <div class="gwm-system-search flex items-center gap-2 rounded-md bg-slate-900 border border-slate-700 px-3 py-2 focus-within:border-indigo-400/60 transition-colors">
                    <svg class="h-3.5 w-3.5 shrink-0 text-slate-500 pointer-events-none" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/></svg>
                    <input
                        type="search"
                        x-model.debounce.150ms="search"
                        @keydown.escape="search = ''"
                        placeholder="{{ __('Search settings…') }}"
                        aria-label="{{ __('Search settings') }}"
                        class="flex-1 min-w-0 bg-transparent border-0 p-0 text-xs text-slate-300 placeholder-slate-500 focus:outline-none focus:ring-0"
                    />
                    <button x-show="search" type="button" @click="search = ''" class="shrink-0 text-slate-500 hover:text-slate-300 transition-colors" aria-label="{{ __('Clear search') }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div
                    x-show="search.trim()"
                    x-cloak
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="absolute top-full left-0 right-0 mt-1 z-50 rounded-md bg-slate-900 border border-slate-700 shadow-2xl overflow-hidden"
                >
                    <template x-if="results.length > 0">
                        <div class="divide-y divide-slate-800">
                            <template x-for="result in results" :key="result.label + result.section">
                                <a :href="result.url" @click="search = ''" class="flex items-baseline justify-between gap-3 px-3 py-2.5 hover:bg-slate-800 transition-colors group">
                                    <span class="text-xs text-slate-200 group-hover:text-white truncate" x-text="result.label"></span>
                                    <span class="shrink-0 text-[10px] text-slate-500 group-hover:text-slate-400" x-text="result.section"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                    <template x-if="results.length === 0">
                        <p class="px-3 py-3 text-xs text-slate-500 text-center">{{ __('No matching settings.') }}</p>
                    </template>
                </div>
            </div>

            <nav class="mt-3 space-y-1.5" aria-label="System navigation">
                <a href="{{ route('system.updates') }}" class="{{ $navItem }} {{ $isSystemTab('updates') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    <span class="inline-flex items-center gap-2">
                        {{ __('App Updates') }}
                        @if ($updateAvailable)
                            <span class="inline-flex items-center justify-center rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">{{ __('New') }}</span>
                        @endif
                    </span>
                </a>

                @if ($isEnterprise)
                    <a href="{{ route('system.support') }}" class="{{ $navItem }} {{ $isSystemTab('support') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path d="M12 16H12.01M12 12H12.01M12 8H12.01M21 14V17C21 18.1046 20.1046 19 19 19H5C3.89543 19 3 18.1046 3 17V14C4.10457 14 5 13.1046 5 12C5 10.8954 4.10457 10 3 10V7C3 5.89543 3.89543 5 5 5H19C20.1046 5 21 5.89543 21 7V10C19.8954 10 19 10.8954 19 12C19 13.1046 19.8954 14 21 14Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="--darkreader-inline-stroke: var(--darkreader-text-ffffff, #e8e6e3);" data-darkreader-inline-stroke=""></path> </g>
                        </svg>
                        {{ __('Enterprise Support') }}
                    </a>
                @else
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Support' } }));" class="{{ $navItem }} {{ $idleNav }} w-full">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path d="M12 16H12.01M12 12H12.01M12 8H12.01M21 14V17C21 18.1046 20.1046 19 19 19H5C3.89543 19 3 18.1046 3 17V14C4.10457 14 5 13.1046 5 12C5 10.8954 4.10457 10 3 10V7C3 5.89543 3.89543 5 5 5H19C20.1046 5 21 5.89543 21 7V10C19.8954 10 19 10.8954 19 12C19 13.1046 19.8954 14 21 14Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="--darkreader-inline-stroke: var(--darkreader-text-ffffff, #e8e6e3);" data-darkreader-inline-stroke=""></path> </g>
                        </svg>
                        <span class="inline-flex items-center gap-1">
                            {{ __('Enterprise Support') }}
                            <svg class="h-3.5 w-3.5 shrink-0 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd"></path>
                            </svg>
                        </span>
                    </button>
                @endif

                <a href="{{ route('system.node') }}" class="{{ $navItem }} {{ $isSystemTab('node') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 24 24" role="img" xmlns="http://www.w3.org/2000/svg" style="--darkreader-inline-fill: var(--darkreader-background-000000, #000000);" data-darkreader-inline-fill=""><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"><title>Node.js icon</title><path d="M11.998,24c-0.321,0-0.641-0.084-0.922-0.247l-2.936-1.737c-0.438-0.245-0.224-0.332-0.08-0.383 c0.585-0.203,0.703-0.25,1.328-0.604c0.065-0.037,0.151-0.023,0.218,0.017l2.256,1.339c0.082,0.045,0.197,0.045,0.272,0l8.795-5.076 c0.082-0.047,0.134-0.141,0.134-0.238V6.921c0-0.099-0.053-0.192-0.137-0.242l-8.791-5.072c-0.081-0.047-0.189-0.047-0.271,0 L3.075,6.68C2.99,6.729,2.936,6.825,2.936,6.921v10.15c0,0.097,0.054,0.189,0.139,0.235l2.409,1.392 c1.307,0.654,2.108-0.116,2.108-0.89V7.787c0-0.142,0.114-0.253,0.256-0.253h1.115c0.139,0,0.255,0.112,0.255,0.253v10.021 c0,1.745-0.95,2.745-2.604,2.745c-0.508,0-0.909,0-2.026-0.551L2.28,18.675c-0.57-0.329-0.922-0.945-0.922-1.604V6.921 c0-0.659,0.353-1.275,0.922-1.603l8.795-5.082c0.557-0.315,1.296-0.315,1.848,0l8.794,5.082c0.57,0.329,0.924,0.944,0.924,1.603 v10.15c0,0.659-0.354,1.273-0.924,1.604l-8.794,5.078C12.643,23.916,12.324,24,11.998,24z M19.099,13.993 c0-1.9-1.284-2.406-3.987-2.763c-2.731-0.361-3.009-0.548-3.009-1.187c0-0.528,0.235-1.233,2.258-1.233 c1.807,0,2.473,0.389,2.747,1.607c0.024,0.115,0.129,0.199,0.247,0.199h1.141c0.071,0,0.138-0.031,0.186-0.081 c0.048-0.054,0.074-0.123,0.067-0.196c-0.177-2.098-1.571-3.076-4.388-3.076c-2.508,0-4.004,1.058-4.004,2.833 c0,1.925,1.488,2.457,3.895,2.695c2.88,0.282,3.103,0.703,3.103,1.269c0,0.983-0.789,1.402-2.642,1.402 c-2.327,0-2.839-0.584-3.011-1.742c-0.02-0.124-0.126-0.215-0.253-0.215h-1.137c-0.141,0-0.254,0.112-0.254,0.253 c0,1.482,0.806,3.248,4.655,3.248C17.501,17.007,19.099,15.91,19.099,13.993z"></path></g></svg>
                    {{ __('Node.js') }}
                </a>

                <a href="{{ route('system.diagnostics') }}" class="{{ $navItem }} {{ $isSystemTab('diagnostics') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" /></svg>
                    {{ __('Runtime Diagnostics') }}
                </a>

                <a href="{{ route('system.scheduler') }}" class="{{ $navItem }} {{ $isSystemTab('scheduler') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 5H3"/><path d="M16 12H3"/><path d="M9 19H3"/><path d="m16 16-3 3 3 3"/><path d="M21 5v12a2 2 0 0 1-2 2h-6"/></svg>
                    <span class="inline-flex items-center gap-2">
                        {{ __('Scheduler & Queue') }}
                        @if ($openAlerts > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $openAlerts }}</span>
                        @endif
                    </span>
                </a>

                <a href="{{ route('system.application') }}" class="{{ $navItem }} {{ $isSystemTab('application') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                    {{ __('App & Security') }}
                </a>

                <a href="{{ route('system.audits') }}" class="{{ $navItem }} {{ $isSystemTab('audits') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" /></svg>
                    {{ __('Audits & Alerts') }}
                </a>

                <a href="{{ route('system.licensing') }}" class="{{ $navItem }} {{ $isSystemTab('licensing') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>
                    <span class="inline-flex items-center gap-2">
                        {{ __('Edition & License') }}
                        @if ($showLocalLicenseBadge)
                            <span class="inline-flex items-center justify-center rounded-full bg-amber-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">{{ __('Fix') }}</span>
                        @endif
                    </span>
                </a>

                <a href="{{ route('system.environment') }}" class="{{ $navItem }} {{ $isSystemTab('environment') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" /></svg>
                    {{ __('Environment Config') }}
                </a>

                <a href="{{ route('system.email') }}" class="{{ $navItem }} {{ $isSystemTab('email') ? $activeNav : $idleNav }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                    {{ __('Email Settings') }}
                </a>

                @if ($isEnterprise)
                    <a href="{{ route('system.white-label') }}" class="{{ $navItem }} {{ $isSystemTab('white-label') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                        {{ __('White Label') }}
                    </a>
                @else
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'White Label Branding' } }));" class="{{ $navItem }} {{ $idleNav }} w-full">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                        <span class="inline-flex items-center gap-1">
                            {{ __('White Label') }}
                            <svg class="h-3.5 w-3.5 shrink-0 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd"></path>
                            </svg>
                        </span>
                    </button>
                @endif
            </nav>
        </div>
    </aside>

    {{-- Mobile drawer (teleported to body) --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            class="lg:hidden fixed inset-0 z-[1100]"
            aria-hidden="true"
        >
            <div
                class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm"
                @click="open = false"
                x-transition:enter="transition-opacity ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
            ></div>
            <div
                class="absolute inset-y-0 left-0 w-[22rem] max-w-[90vw] bg-slate-950 border-r border-slate-800 flex flex-col overflow-y-auto"
                x-transition:enter="transition-transform ease-out duration-250"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform ease-in duration-200"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                x-data="{
                    search: '',
                    get results() {
                        const q = this.search.trim().toLowerCase();
                        if (!q) return [];
                        return (window.__gwmSettingsIdx || [])
                            .filter(item => (item.label + ' ' + item.keys).toLowerCase().includes(q))
                            .slice(0, 9);
                    }
                }"
            >
                <div class="flex items-start justify-between px-4 py-4 border-b border-slate-800">
                    <div>
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">{{ __('System') }}</div>
                        <div class="mt-1 text-lg font-semibold text-white">{{ __('Control Center') }}</div>
                        <div class="text-xs text-slate-400">{{ __('Updates, security, settings, and platform services.') }}</div>
                    </div>
                    <button type="button" @click="open = false" class="mt-0.5 rounded-lg p-1.5 text-slate-400 hover:text-white hover:bg-slate-800 transition shrink-0">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="px-4 pt-3 pb-1">
                    <div class="relative" @click.outside="search = ''">
                        <div class="gwm-system-search flex items-center gap-2 rounded-md bg-slate-900 border border-slate-700 px-3 py-2 focus-within:border-indigo-400/60 transition-colors">
                            <svg class="h-3.5 w-3.5 shrink-0 text-slate-500 pointer-events-none" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/></svg>
                            <input
                                type="search"
                                x-model.debounce.150ms="search"
                                @keydown.escape="search = ''"
                                placeholder="{{ __('Search settings…') }}"
                                aria-label="{{ __('Search settings') }}"
                                class="flex-1 min-w-0 bg-transparent border-0 p-0 text-xs text-slate-300 placeholder-slate-500 focus:outline-none focus:ring-0"
                            />
                            <button x-show="search" type="button" @click="search = ''" class="shrink-0 text-slate-500 hover:text-slate-300 transition-colors" aria-label="{{ __('Clear search') }}">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div
                            x-show="search.trim()"
                            x-cloak
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            class="absolute top-full left-0 right-0 mt-1 z-50 rounded-md bg-slate-900 border border-slate-700 shadow-2xl overflow-hidden"
                        >
                            <template x-if="results.length > 0">
                                <div class="divide-y divide-slate-800">
                                    <template x-for="result in results" :key="result.label + result.section">
                                        <a :href="result.url" @click="search = ''; open = false" class="flex items-baseline justify-between gap-3 px-3 py-2.5 hover:bg-slate-800 transition-colors group">
                                            <span class="text-xs text-slate-200 group-hover:text-white truncate" x-text="result.label"></span>
                                            <span class="shrink-0 text-[10px] text-slate-500 group-hover:text-slate-400" x-text="result.section"></span>
                                        </a>
                                    </template>
                                </div>
                            </template>
                            <template x-if="results.length === 0">
                                <p class="px-3 py-3 text-xs text-slate-500 text-center">{{ __('No matching settings.') }}</p>
                            </template>
                        </div>
                    </div>
                </div>

                <nav class="p-4 space-y-1.5" aria-label="System navigation">
                    <a href="{{ route('system.updates') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('updates') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        <span class="inline-flex items-center gap-2">
                            {{ __('App Updates') }}
                            @if ($updateAvailable)
                                <span class="inline-flex items-center justify-center rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">{{ __('New') }}</span>
                            @endif
                        </span>
                    </a>

                    @if ($isEnterprise)
                        <a href="{{ route('system.support') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('support') ? $activeNav : $idleNav }}">
                            <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                            </svg>
                            {{ __('Enterprise Support') }}
                        </a>
                    @else
                        <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Support' } })); open = false" class="{{ $navItem }} {{ $idleNav }} w-full">
                            <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                            </svg>
                            <span class="inline-flex items-center gap-1">
                                {{ __('Enterprise Support') }}
                                <svg class="h-3 w-3 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                            </span>
                        </button>
                    @endif

                    <a href="{{ route('system.node') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('node') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 24 24" role="img" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_iconCarrier"><path d="M11.998,24c-0.321,0-0.641-0.084-0.922-0.247l-2.936-1.737c-0.438-0.245-0.224-0.332-0.08-0.383 c0.585-0.203,0.703-0.25,1.328-0.604c0.065-0.037,0.151-0.023,0.218,0.017l2.256,1.339c0.082,0.045,0.197,0.045,0.272,0l8.795-5.076 c0.082-0.047,0.134-0.141,0.134-0.238V6.921c0-0.099-0.053-0.192-0.137-0.242l-8.791-5.072c-0.081-0.047-0.189-0.047-0.271,0 L3.075,6.68C2.99,6.729,2.936,6.825,2.936,6.921v10.15c0,0.097,0.054,0.189,0.139,0.235l2.409,1.392 c1.307,0.654,2.108-0.116,2.108-0.89V7.787c0-0.142,0.114-0.253,0.256-0.253h1.115c0.139,0,0.255,0.112,0.255,0.253v10.021 c0,1.745-0.95,2.745-2.604,2.745c-0.508,0-0.909,0-2.026-0.551L2.28,18.675c-0.57-0.329-0.922-0.945-0.922-1.604V6.921 c0-0.659,0.353-1.275,0.922-1.603l8.795-5.082c0.557-0.315,1.296-0.315,1.848,0l8.794,5.082c0.57,0.329,0.924,0.944,0.924,1.603 v10.15c0,0.659-0.354,1.273-0.924,1.604l-8.794,5.078C12.643,23.916,12.324,24,11.998,24z M19.099,13.993 c0-1.9-1.284-2.406-3.987-2.763c-2.731-0.361-3.009-0.548-3.009-1.187c0-0.528,0.235-1.233,2.258-1.233 c1.807,0,2.473,0.389,2.747,1.607c0.024,0.115,0.129,0.199,0.247,0.199h1.141c0.071,0,0.138-0.031,0.186-0.081 c0.048-0.054,0.074-0.123,0.067-0.196c-0.177-2.098-1.571-3.076-4.388-3.076c-2.508,0-4.004,1.058-4.004,2.833 c0,1.925,1.488,2.457,3.895,2.695c2.88,0.282,3.103,0.703,3.103,1.269c0,0.983-0.789,1.402-2.642,1.402 c-2.327,0-2.839-0.584-3.011-1.742c-0.02-0.124-0.126-0.215-0.253-0.215h-1.137c-0.141,0-0.254,0.112-0.254,0.253 c0,1.482,0.806,3.248,4.655,3.248C17.501,17.007,19.099,15.91,19.099,13.993z"></path></g></svg>
                        {{ __('Node.js') }}
                    </a>

                    <a href="{{ route('system.diagnostics') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('diagnostics') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" /></svg>
                        {{ __('Runtime Diagnostics') }}
                    </a>

                    <a href="{{ route('system.scheduler') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('scheduler') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 5H3"/><path d="M16 12H3"/><path d="M9 19H3"/><path d="m16 16-3 3 3 3"/><path d="M21 5v12a2 2 0 0 1-2 2h-6"/></svg>
                        <span class="inline-flex items-center gap-2">
                            {{ __('Scheduler & Queue') }}
                            @if ($openAlerts > 0)
                                <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-rose-200">{{ $openAlerts }}</span>
                            @endif
                        </span>
                    </a>

                    <a href="{{ route('system.application') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('application') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                        {{ __('App & Security') }}
                    </a>

                    <a href="{{ route('system.audits') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('audits') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" /></svg>
                        {{ __('Audits & Alerts') }}
                    </a>

                    <a href="{{ route('system.licensing') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('licensing') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg>
                        <span class="inline-flex items-center gap-2">
                            {{ __('Edition & License') }}
                            @if ($showLocalLicenseBadge)
                                <span class="inline-flex items-center justify-center rounded-full bg-amber-500/20 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-200">{{ __('Fix') }}</span>
                            @endif
                        </span>
                    </a>

                    <a href="{{ route('system.environment') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('environment') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" /></svg>
                        {{ __('Environment Config') }}
                    </a>

                    <a href="{{ route('system.email') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('email') ? $activeNav : $idleNav }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                        {{ __('Email Settings') }}
                    </a>

                    @if ($isEnterprise)
                        <a href="{{ route('system.white-label') }}" @click="open = false" class="{{ $navItem }} {{ $isSystemTab('white-label') ? $activeNav : $idleNav }}">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                            {{ __('White Label') }}
                        </a>
                    @else
                        <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'White Label Branding' } })); open = false" class="{{ $navItem }} {{ $idleNav }} w-full">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                            <span class="inline-flex items-center gap-1">
                                {{ __('White Label') }}
                                <svg class="h-3 w-3 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                            </span>
                        </button>
                    @endif
                </nav>
            </div>
        </div>
    </template>
</div>
