<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full dark">
    <head>
        <meta charset="utf-8">
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
                .gpm-fallback-alert { max-width: 36rem; margin: 1.5rem auto; padding: 0.75rem 1rem; border-radius: 0.75rem; background: #1f2937; border: 1px solid #334155; text-align: center; }
                .gpm-fallback-alert strong { color: #f8fafc; }
            </style>
        @endif
    </head>
    <body class="min-h-screen font-sans antialiased h-full bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="h-full flex flex-col sm:justify-center items-center pt-6 sm:pt-0">
            @if (! $viteReady)
                <div class="gpm-fallback-alert">
                    <strong>Assets are not built.</strong> Run <code>npm run build</code> to restore the styled UI.
                </div>
            @endif
            <div>
                <a href="/" class="flex items-center">
                    <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                    <div>
                        <h2 class="text-xl px-2 font-semibold text-slate-900 dark:text-slate-100">
                            Git Project Manager
                        </h2>
                    </div>
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg dark:bg-slate-900 dark:border dark:border-slate-800">
                {{ $slot }}
            </div>
            <p class="footer-text">© 2026 <a href="https://wallabydesigns.com/" title="Website built by Wallaby Designs">Wallaby Designs LLC</a>. All rights reserved.</p>
        </div>
    </body>
</html>

