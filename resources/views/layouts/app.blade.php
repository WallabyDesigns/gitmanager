<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">

        <link rel="icon" type="image/x-icon" href="/favicons/favicon.ico" >
        <link rel="icon" type="image/png" href="/favicons/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicons/favicon.svg" />
        <link rel="shortcut icon" href="/favicons/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png" />
        <meta name="apple-mobile-web-app-title" content="GWM" />
        <link rel="manifest" href="/favicons/site.webmanifest" />
        
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @php
            $editionService = app(\App\Services\EditionService::class);
            $settingsService = app(\App\Services\SettingsService::class);
            $isEnterpriseEdition = $editionService->current() === \App\Services\EditionService::ENTERPRISE;
            $brandName = (string) config('app.name', 'Git Web Manager');
            if ($isEnterpriseEdition) {
                $customBrandName = trim((string) $settingsService->get('system.white_label.name', ''));
                if ($customBrandName !== '') {
                    $brandName = $customBrandName;
                }
            }
        @endphp

        <title>{{ isset($title) ? $title . ' - ' . __($brandName) : __($brandName) }}</title>

        <meta name="color-scheme" content="dark">


        <link rel="stylesheet" href="{{ asset('css/app.min.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
        @if (class_exists(\GitManagerEnterprise\EnterpriseServiceProvider::class))
            <link rel="stylesheet" href="{{ asset('vendor/gitmanager-enterprise/gitmanager-enterprise.css') }}?v={{ file_exists(public_path('vendor/gitmanager-enterprise/gitmanager-enterprise.css')) ? filemtime(public_path('vendor/gitmanager-enterprise/gitmanager-enterprise.css')) : time() }}">
        @endif
        <script src="{{ asset('js/app.js') }}" defer></script>
        @livewireStyles
        <style>
            [x-cloak] { display: none !important; }
            :where(.nonrenderhide){
                opacity: 0;
            }
        </style>
    </head>
    <body class="gwm-app nonrenderhide gwm-visible">
        <div class="gwm-page-shell">
            @auth
                @include('partials.recovery-panel', [
                    'forceVisible' => false,
                    'overlay' => true,
                    'status' => session('repair_status'),
                ])
            @endauth

            @persist('nav')
                <livewire:layout.navigation />
            @endpersist

            <!-- Page Heading -->
            @if (isset($header))
                <header class="gwm-page-header">
                    <div class="gwm-page-header-inner">
                        {!! $header !!}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main class="gwm-page-main">
                {{ $slot }}
            </main>
            <footer class="gwm-page-footer">
                <p class="footer-text">{{ __('Git Web Manager for Git') }} © 2026 <a style="text-decoration: underline;" href="https://wallabydesigns.com/" title="{{ __('Website built by Wallaby Designs') }}">{{ __('Wallaby Designs LLC') }}</a> • zlib License<br/>
                <span class="footer-disclaimer">{{ __('Git Web Manager is not affiliated with, endorsed by, or sponsored by Git or its maintainers.') }}</span></p>
            </footer>
        </div>

        <div id="gwm-toast" class="gwm-toast fixed bottom-6 right-6 hidden max-w-sm rounded-lg border px-4 py-3 text-sm shadow-lg"></div>
        @include('partials.language-selector')
        <div
            x-data="{ open: false, feature: @js(__('Enterprise Feature')) }"
            x-on:gwm-open-enterprise-modal.window="feature = ($event.detail && $event.detail.feature) ? $event.detail.feature : @js(__('Enterprise Feature')); open = true"
            x-on:keydown.escape.window="open = false"
        >
            <div
                x-show="open"
                x-cloak
                class="fixed inset-0 z-[1300] flex items-center justify-center px-4"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" @click="open = false"></div>
                <div class="relative w-full max-w-lg rounded-2xl border p-6 shadow-2xl border-amber-500/30 bg-slate-900">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wide border-amber-500/50 bg-amber-500/10 text-amber-300">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd" />
                                </svg>
                                {{ __('Enterprise Feature') }}
                            </div>
<h3 class="mt-3 text-lg font-semibold text-slate-100">{{ __('Unlock in Enterprise Edition') }}</h3>
                    </div>
                    <button type="button" @click="open = false" class="rounded-md p-2 text-slate-300 hover:text-slate-100" aria-label="{{ __('Close') }}">
                        <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <p class="mt-4 text-sm text-slate-300">
                    <span class="font-semibold text-slate-100" x-text="feature"></span>
                    {{ __('is available in Enterprise Edition. Upgrade to unlock premium infrastructure controls and advanced platform features.') }}
                </p>

                <ul class="mt-4 space-y-2 text-sm text-slate-300">
                    <li>{{ __('Docker and container management from the project panel.') }}</li>
                    <li>{{ __('Kubernetes workload controls and health visibility.') }}</li>
                    <li>{{ __('SQL database provisioning and management tools.') }}</li>
                    <li>{{ __('White-label branding with custom logo and identity.') }}</li>
                </ul>

                    @php
                        $checkoutLive = \Illuminate\Support\Facades\Route::has('checkout.enterprise')
                            && class_exists(\GitManagerEnterprise\Http\Controllers\CheckoutController::class)
                            && \GitManagerEnterprise\Http\Controllers\CheckoutController::isConfigured();
                    @endphp
                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        @if ($checkoutLive)
                            @auth
                                <form method="POST" action="{{ route('checkout.enterprise') }}" id="gwm-enterprise-checkout-form">
                                    @csrf
                                    <input type="hidden" name="locale" value="{{ \App\Support\LanguageOptions::normalize(auth()->user()?->locale ?? session('locale') ?? app()->getLocale()) }}">
                                </form>
                                <button type="button" @click="open = false; document.getElementById('gwm-enterprise-checkout-form').submit()" class="inline-flex items-center rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-400">
                                    {{ __('Continue to Checkout') }}
                                </button>
                            @else
                                <a href="{{ route('login') }}" class="inline-flex items-center rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-400">
                                    {{ __('Sign In to Purchase') }}
                                </a>
                            @endauth
                        @else
                            <a href="mailto:hello@wallabydesigns.com?subject=Enterprise%20Edition%20Enquiry" class="inline-flex items-center rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-400">
                                {{ __('Contact Us to Purchase') }}
                            </a>
                        @endif
                        <button type="button" @click="open = false" class="inline-flex items-center rounded-md border px-4 py-2 text-sm border-slate-700 text-slate-200 hover:text-white">
                            {{ __('Maybe Later') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @if (session()->has('gwm_flash'))
            @php $gwmFlash = session('gwm_flash'); @endphp
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    window.dispatchEvent(new CustomEvent('notify', { detail: {
                        type: {{ Js::from($gwmFlash['type'] ?? 'info') }},
                        message: {{ Js::from($gwmFlash['message'] ?? '') }},
                    }}));
                });
            </script>
        @endif
        <script>
            window.addEventListener('notify', (event) => {
                const toast = document.getElementById('gwm-toast');
                if (!toast) {
                    return;
                }
                const variants = {
                    success: ['border-emerald-500/40', 'bg-emerald-500/10', 'text-emerald-200'],
                    error: ['border-rose-500/40', 'bg-rose-500/10', 'text-rose-200'],
                    warning: ['border-amber-500/40', 'bg-amber-500/10', 'text-amber-200'],
                    info: ['border-slate-800', 'bg-slate-900', 'text-slate-200'],
                };
                const variantClasses = Object.values(variants).flat();
                const type = event.detail?.type || 'info';
                const active = variants[type] || variants.info;
                toast.classList.remove(...variantClasses);
                toast.classList.add(...active);
                toast.textContent = event.detail.message || @js(__('Done')).concat('.');
                toast.classList.remove('hidden');
                clearTimeout(window.GWMToastTimer);
                window.GWMToastTimer = setTimeout(() => {
                    toast.classList.add('hidden');
                }, 4000);
            });

            window.addEventListener('reload-page', (event) => {
                const delay = Number(event.detail?.delay ?? 500);
                window.setTimeout(() => {
                    window.location.reload();
                }, Number.isNaN(delay) ? 500 : delay);
            });

        </script>
        @livewireScripts

        {{-- Update-in-progress overlay --}}
        <style>
            @keyframes gwm-spin { to { transform: rotate(360deg); } }
            #gwm-update-overlay {
                display: none; position: fixed; inset: 0; z-index: 9999;
                background: rgba(2,6,23,0.93); backdrop-filter: blur(4px);
                align-items: center; justify-content: center;
                font-family: system-ui, -apple-system, sans-serif;
            }
            #gwm-update-overlay .gwm-uo-card {
                text-align: center; padding: 2.5rem 2rem;
                background: rgba(15,23,42,0.95);
                border: 1px solid rgba(148,163,184,0.15);
                border-radius: 18px; width: min(480px, 90vw);
                box-shadow: 0 20px 45px rgba(2,6,23,0.6);
            }
            #gwm-update-overlay .gwm-uo-spinner {
                width: 44px; height: 44px;
                border: 3px solid rgba(255,255,255,0.15);
                border-top-color: #ffffff;
                border-radius: 50%; animation: gwm-spin 0.85s linear infinite;
                margin: 0 auto 1.25rem;
            }
            #gwm-update-overlay .gwm-uo-title { color: #f1f5f9; font-size: 1.1rem; font-weight: 700; margin: 0 0 0.3rem; }
            #gwm-update-overlay .gwm-uo-sub   { color: #64748b; font-size: 0.85rem; margin: 0; line-height: 1.6; }
            #gwm-update-overlay .gwm-uo-retry { color: #475569; font-size: 0.78rem; margin-top: 0.75rem; }
            #gwm-update-overlay .gwm-uo-retry span { color: #94a3b8; font-weight: 600; }
            #gwm-update-overlay .gwm-uo-recovery { display: none; margin-top: 1rem; }
            #gwm-update-overlay .gwm-uo-recovery a {
                display: inline-block; padding: 0.45rem 1rem;
                border-radius: 8px; border: 1px solid rgba(148,163,184,0.3);
                background: rgba(30,41,59,0.8); color: #cbd5e1;
                text-decoration: none; font-size: 0.82rem; font-weight: 500;
            }
            #gwm-update-overlay .gwm-uo-recovery a:hover { border-color: rgba(148,163,184,0.6); color: #f1f5f9; }
            #gwm-update-overlay .gwm-uo-log  { margin-top: 1.25rem; text-align: left; }
            #gwm-update-overlay details       { border-radius: 8px; border: 1px solid rgba(148,163,184,0.12); overflow: hidden; }
            #gwm-update-overlay summary {
                cursor: pointer; padding: 0.55rem 0.85rem; font-size: 0.78rem;
                color: #64748b; background: rgba(30,41,59,0.5);
                user-select: none; list-style: none;
                display: flex; align-items: center; gap: 0.4rem;
            }
            #gwm-update-overlay summary::-webkit-details-marker { display: none; }
            #gwm-update-overlay summary::before { content: '▶'; font-size: 0.55rem; transition: transform 0.2s; }
            #gwm-update-overlay details[open] summary::before { transform: rotate(90deg); }
            #gwm-update-overlay #gwm-uo-log-body {
                padding: 0.65rem 0.85rem; font-size: 0.7rem;
                font-family: ui-monospace, "Cascadia Code", monospace;
                color: #94a3b8; background: rgba(2,6,23,0.6);
                max-height: 160px; overflow-y: auto;
                white-space: pre-wrap; word-break: break-word;
            }
        </style>

        <div id="gwm-update-overlay">
            <div class="gwm-uo-card">
                <div style="display:flex;justify-content:center;margin-bottom:1.25rem;">
                    <svg viewBox="0 0 341.41 340.88" xmlns="http://www.w3.org/2000/svg" style="width:40px;height:40px;" aria-hidden="true">
                        <path d="M100.6,221.15l-18.54,37.88L5.73,182.7c-6-6-6-15.74,0-21.74l34.79-34.79,56.51,64.84c-2.69,3.68-4.28,8.22-4.28,13.14,0,6.81,3.05,12.91,7.85,17Z" style="fill:#f15a29;"/>
                        <path d="M334.83,182.7l-48.42,48.42-11.61-27.88,36.75-.64-82.46-82.46-.13,113.23,26.02-24.67,15.98,37.86-89.82,89.82c-6,6-15.73,6-21.73,0l-68.38-68.38,20.47-41.8c1.17.19,2.37.29,3.59.29,3.82,0,7.41-.96,10.55-2.65l25.98,29.81c-2.33,3.52-3.68,7.74-3.68,12.28,0,12.33,10,22.33,22.34,22.33s22.34-10,22.34-22.33-10.01-22.34-22.34-22.34c-3.44,0-6.71.78-9.62,2.17l-26.35-30.23c1.98-3.33,3.12-7.22,3.12-11.38,0-6.46-2.75-12.29-7.14-16.35l41.27-84.3c1.32.25,2.68.38,4.07.38,12.33,0,22.33-10,22.33-22.34s-10-22.34-22.33-22.34-22.34,10-22.34,22.34c0,6.64,2.89,12.6,7.49,16.69l-41.15,84.05c-1.46-.3-2.98-.46-4.54-.46-3.06,0-5.98.62-8.64,1.74l-57.43-65.89L159.41,7.28c6-6,15.73-6,21.73,0l153.69,153.68c6,6,6,15.74,0,21.74Z" style="fill:#f15a29;"/>
                    </svg>
                </div>
                <div class="gwm-uo-spinner"></div>
                <p class="gwm-uo-title">Update in Progress</p>
                <p class="gwm-uo-sub">Waiting for the app to come back online&hellip;</p>
                <div class="gwm-uo-retry">Retrying in <span id="gwm-uo-countdown">10</span>s</div>
                <div class="gwm-uo-recovery" id="gwm-uo-recovery">
                    <a href="/recovery">Open Recovery Page</a>
                </div>
                <div class="gwm-uo-log">
                    <details>
                        <summary>Update log</summary>
                        <div id="gwm-uo-log-body">Waiting…</div>
                    </details>
                </div>
            </div>
        </div>

        <script>
            (function () {
                var overlay   = document.getElementById('gwm-update-overlay');
                var countEl   = document.getElementById('gwm-uo-countdown');
                var logEl     = document.getElementById('gwm-uo-log-body');
                var recovery  = document.getElementById('gwm-uo-recovery');
                var visible   = false;
                var elapsed   = 0;
                var retryIn   = 10;
                var tickTimer = null;

                function setLog(text) {
                    if (logEl) { logEl.textContent = text; logEl.scrollTop = logEl.scrollHeight; }
                }

                function fetchLog() {
                    fetch('/update/status', { cache: 'no-store', credentials: 'same-origin' })
                        .then(function (r) {
                            if (r.ok) return r.text();
                            return Promise.reject(r.status);
                        })
                        .then(function (text) { setLog(text || 'No log content yet.'); })
                        .catch(function () { setLog('Update log unavailable while app is restarting…'); });
                }

                function checkOnline() {
                    fetch(window.location.href, { method: 'HEAD', cache: 'no-store' })
                        .then(function (r) { if (r.ok) window.location.reload(); })
                        .catch(function () { /* still down */ });
                }

                function startTicker() {
                    if (tickTimer) return;
                    tickTimer = setInterval(function () {
                        elapsed++; retryIn--;
                        if (elapsed === 60 && recovery) {
                            recovery.style.display = 'block';
                        }
                        if (retryIn <= 0) { retryIn = 10; checkOnline(); fetchLog(); }
                        if (countEl) countEl.textContent = retryIn;
                    }, 1000);
                }

                function showOverlay() {
                    if (visible) return;
                    visible = true;
                    overlay.style.display = 'flex';
                    fetchLog();
                    startTicker();
                }

                // Livewire v3 hook
                document.addEventListener('livewire:init', function () {
                    Livewire.hook('request', function (_ref) {
                        var fail = _ref.fail;
                        if (typeof fail !== 'function') return;
                        fail(function (_ref2) {
                            var status = _ref2.status, preventDefault = _ref2.preventDefault;
                            if (status === 503 || status === 0) {
                                if (typeof preventDefault === 'function') preventDefault();
                                showOverlay();
                            }
                        });
                    });
                });

                // Fallback: patch fetch for non-Livewire 503s
                var _orig = window.fetch;
                window.fetch = function () {
                    return _orig.apply(this, arguments)
                        .then(function (r) { if (r.status === 503) showOverlay(); return r; })
                        .catch(function (e) { showOverlay(); return Promise.reject(e); });
                };
            })();
        </script>
    </body>
</html>
