<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="h-full">
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

        @php
            $editionLabel = $editionService->label();
        @endphp
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        @if (class_exists(\GitManagerEnterprise\EnterpriseServiceProvider::class))
            <link rel="stylesheet" href="{{ asset('vendor/gitmanager-enterprise/gitmanager-enterprise.css') }}">
        @endif
        <script src="{{ asset('js/app.js') }}" defer></script>
        @livewireStyles
        <style>
            body { transition: opacity 0.12s ease; }
            body.gwm-preload { opacity: 0; }
        </style>
        <noscript><style>body.gwm-preload { opacity: 1 !important; }</style></noscript>
    </head>
    <body class="gwm-preload min-h-screen font-sans antialiased h-full bg-slate-950 text-slate-100">
        <div class="h-full flex flex-col sm:justify-center items-center pt-6 sm:pt-0">
            <div>
                <a href="/" class="flex items-center">
                    <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                    <div>
                        <h2 class="text-xl px-2 font-semibold text-slate-100">
                            {{ $brandName }}
                        </h2>
                        <p class="px-2 -mt-1 text-[11px] uppercase tracking-[0.12em] text-slate-400">
                            {{ $editionLabel }}
                        </p>
                    </div>
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 shadow-md overflow-hidden sm:rounded-lg bg-slate-900 border border-slate-800">
                {{ $slot }}
            </div>
            <p class="footer-text">{{ __('Git Web Manager for Git') }} © 2026 <a style="text-decoration: underline;" href="https://wallabydesigns.com/" title="{{ __('Website built by Wallaby Designs') }}">{{ __('Wallaby Designs LLC') }}</a> • zlib License<br/>
            <span class="footer-disclaimer">{{ __('Git Web Manager is not affiliated with, endorsed by, or sponsored by Git or its maintainers.') }}</span></p>
        </div>
        @include('partials.language-selector', ['floating' => true])
        <script>
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
        <script data-navigate-once="true">
            var Alpine = window.Alpine || {};
            Alpine.navigate = Alpine.navigate || { disableProgressBar() {} };
            window.Alpine = Alpine;
        </script>
        @livewireScripts
        <script data-navigate-once="true">
            document.addEventListener('livewire:init', () => {
                window.Alpine?.navigate?.disableProgressBar?.();
            }, { once: true });
        </script>
    </body>
</html>
