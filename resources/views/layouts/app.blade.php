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
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased h-full bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="min-h-screen">
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
        </div>

        <div id="gpm-toast" class="fixed bottom-6 right-6 hidden max-w-sm rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-lg dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"></div>
        <script>
            window.addEventListener('notify', (event) => {
                const toast = document.getElementById('gpm-toast');
                if (!toast) {
                    return;
                }
                toast.textContent = event.detail.message || 'Done.';
                toast.classList.remove('hidden');
                clearTimeout(window.GPMToastTimer);
                window.GPMToastTimer = setTimeout(() => {
                    toast.classList.add('hidden');
                }, 4000);
            });
        </script>
    </body>
</html>
