<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Recovery | {{ config('app.name', 'Git Web Manager') }}</title>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        @if (class_exists(\GitManagerEnterprise\EnterpriseServiceProvider::class))
            <link rel="stylesheet" href="{{ asset('vendor/gitmanager-enterprise/gitmanager-enterprise.css') }}">
        @endif
        <script src="{{ asset('js/app.js') }}" defer></script>
    </head>
    <body class="bg-slate-950 text-slate-100 min-h-screen">
        @include('partials.recovery-panel', [
            'forceVisible' => true,
            'overlay' => false,
            'status' => $status ?? null,
            'output' => $log ?? '',
        ])

        @include('partials.env-backup-panel', [
            'backups' => $envBackups ?? [],
            'status' => $envStatus ?? null,
        ])

        @include('partials.language-selector', ['floating' => true])
    </body>
</html>
