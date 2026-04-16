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

        <title>{{ isset($title) ? $title . ' - ' . config('app.name', 'Git Web Manager') : config('app.name', 'Git Web Manager') }}</title>

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
                <p class="footer-text">Git Web Manager for Git © 2026 <a style="text-decoration: underline;" href="https://wallabydesigns.com/" title="Website built by Wallaby Designs">Wallaby Designs LLC</a> • MIT License<br/>
                <span class="footer-disclaimer">Git Web Manager is not affiliated with, endorsed by, or sponsored by Git or its maintainers.</span></p>
            </footer>
        </div>

        <div id="gwm-toast" class="gwm-toast fixed bottom-6 right-6 hidden max-w-sm rounded-lg border px-4 py-3 text-sm shadow-lg"></div>
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
                document.addEventListener('livewire:navigated', reveal);
                window.setTimeout(reveal, 2000);

                if (document.readyState === 'complete') {
                    reveal();
                }
            })();

        </script>
    </body>
</html>
