<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full dark">
    <head>
        <meta charset="utf-8">

        <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

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
    </head>
    <body class="font-sans antialiased h-full bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="min-h-screen">
            @if (! $viteReady)
                <div class="gwm-fallback-alert">
                    <strong>Assets are not built.</strong> Run <code>npm run build</code> or fix the build permissions to restore the normal UI.
                </div>
            @endif

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

        <div id="gwm-toast" class="fixed bottom-6 right-6 hidden max-w-sm rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-lg dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"></div>
        <script>
            window.addEventListener('notify', (event) => {
                const toast = document.getElementById('gwm-toast');
                if (!toast) {
                    return;
                }
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
        </script>
    </body>
</html>
