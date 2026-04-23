<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full dark">
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

        <title>{{ isset($title) ? $title . ' - ' . $brandName : $brandName }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @php
            $viteManifest = public_path('build/manifest.json');
            $viteHot = public_path('hot');
            $viteReady = file_exists($viteManifest) || file_exists($viteHot);
        @endphp
        @if ($viteReady)
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <style>
                body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #0f172a; color: #e2e8f0; }
                a { color: #fbbf24; text-decoration: underline; }
                .gwm-fallback-alert { max-width: 72rem; margin: 1.5rem auto; padding: 0.75rem 1rem; border-radius: 0.75rem; background: #1f2937; border: 1px solid #334155; }
                .gwm-fallback-alert strong { color: #f8fafc; }
            </style>
        @endif
        <style>
            [x-cloak] { display: none !important; }
            body { transition: opacity 0.12s ease; }
            body.gwm-preload { opacity: 0; }
        </style>
        <noscript><style>body.gwm-preload { opacity: 1 !important; }</style></noscript>
    </head>
    <body class="gwm-preload font-sans antialiased h-full bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="min-h-screen">
            @if (! $viteReady)
                <div class="gwm-fallback-alert">
                    <strong>Assets are not built.</strong> Run <code>npm run build</code> or fix the build permissions to restore the normal UI.
                </div>
            @endif

            @auth
                @include('partials.recovery-panel', [
                    'forceVisible' => false,
                    'overlay' => true,
                    'status' => session('rebuild_status'),
                ])
            @endauth

            <livewire:layout.navigation />

            <!-- Page Heading -->
            @if (isset($header))
                <header class="relative z-10 bg-white/80 dark:bg-slate-900/80 shadow backdrop-blur border-b border-slate-200/60 dark:border-slate-800">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {!! $header !!}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
            <footer>
                <p class="footer-text">Git Web Manager for Git © 2026 <a style="text-decoration: underline;" href="https://wallabydesigns.com/" title="Website built by Wallaby Designs">Wallaby Designs LLC</a> • zlib License<br/>
                <span class="footer-disclaimer">Git Web Manager is not affiliated with, endorsed by, or sponsored by Git or its maintainers.</span></p>
            </footer>
        </div>

        <div id="gwm-toast" class="gwm-toast fixed bottom-6 right-6 hidden max-w-sm rounded-lg border px-4 py-3 text-sm shadow-lg"></div>
        <div
            x-data="{ open: false, feature: 'Enterprise Feature' }"
            x-on:gwm-open-enterprise-modal.window="feature = ($event.detail && $event.detail.feature) ? $event.detail.feature : 'Enterprise Feature'; open = true"
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
                <div class="relative w-full max-w-lg rounded-2xl border border-amber-300/40 bg-white p-6 shadow-2xl dark:border-amber-500/30 dark:bg-slate-900">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="inline-flex items-center gap-2 rounded-full border border-amber-300/60 bg-amber-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-amber-700 dark:border-amber-500/50 dark:bg-amber-500/10 dark:text-amber-300">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm-2 6V5a2 2 0 114 0v2H8z" clip-rule="evenodd" />
                                </svg>
                                Enterprise Feature
                            </div>
                            <h3 class="mt-3 text-lg font-semibold text-slate-900 dark:text-slate-100">Unlock in Enterprise Edition</h3>
                        </div>
                        <button type="button" @click="open = false" class="rounded-md p-2 text-slate-500 hover:text-slate-700 dark:text-slate-300 dark:hover:text-slate-100" aria-label="Close">
                            <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">
                        <span class="font-semibold text-slate-900 dark:text-slate-100" x-text="feature"></span>
                        is available in Enterprise Edition. Upgrade to unlock premium infrastructure controls and advanced platform features.
                    </p>

                    <ul class="mt-4 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                        <li>Docker and container management from the project panel.</li>
                        <li>Kubernetes workload controls and health visibility.</li>
                        <li>SQL database provisioning and management tools.</li>
                        <li>White-label branding with custom logo and identity.</li>
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
                                </form>
                                <button type="button" @click="open = false; document.getElementById('gwm-enterprise-checkout-form').submit()" class="inline-flex items-center rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-400">
                                    Continue to Checkout
                                </button>
                            @else
                                <a href="{{ route('login') }}" class="inline-flex items-center rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-400">
                                    Sign In to Purchase
                                </a>
                            @endauth
                        @else
                            <a href="mailto:hello@wallabydesigns.com?subject=Enterprise%20Edition%20Enquiry" class="inline-flex items-center rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-400">
                                Contact Us to Purchase
                            </a>
                        @endif
                        <button type="button" @click="open = false" class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                            Maybe Later
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
                    success: ['border-emerald-300/70', 'bg-emerald-50', 'text-emerald-800', 'dark:border-emerald-500/40', 'dark:bg-emerald-500/10', 'dark:text-emerald-200'],
                    error: ['border-rose-300/70', 'bg-rose-50', 'text-rose-800', 'dark:border-rose-500/40', 'dark:bg-rose-500/10', 'dark:text-rose-200'],
                    warning: ['border-amber-300/70', 'bg-amber-50', 'text-amber-800', 'dark:border-amber-500/40', 'dark:bg-amber-500/10', 'dark:text-amber-200'],
                    info: ['border-slate-200', 'bg-white', 'text-slate-700', 'dark:border-slate-800', 'dark:bg-slate-900', 'dark:text-slate-200'],
                };
                const variantClasses = Object.values(variants).flat();
                const type = event.detail?.type || 'info';
                const active = variants[type] || variants.info;
                toast.classList.remove(...variantClasses);
                toast.classList.add(...active);
                toast.textContent = event.detail.message || 'Done.';
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

            (() => {
                const preloadClass = 'gwm-preload';
                let revealed = false;
                const reveal = () => {
                    if (revealed) {
                        return;
                    }
                    revealed = true;
                    document.body?.classList.remove(preloadClass);
                };

                window.addEventListener('load', reveal, { once: true });
                document.addEventListener('livewire:navigating', () => { revealed = false; });
                document.addEventListener('livewire:navigated', reveal);
                window.setTimeout(reveal, 2000);

                if (document.readyState === 'complete') {
                    reveal();
                }
            })();

        </script>
    </body>
</html>
