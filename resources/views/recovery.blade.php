<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Recovery | {{ config('app.name', 'Git Web Manager') }}</title>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
        @if (class_exists(\GitManagerEnterprise\EnterpriseServiceProvider::class))
            <link rel="stylesheet" href="{{ asset('vendor/gitmanager-enterprise/gitmanager-enterprise.css') }}?v={{ file_exists(public_path('vendor/gitmanager-enterprise/gitmanager-enterprise.css')) ? filemtime(public_path('vendor/gitmanager-enterprise/gitmanager-enterprise.css')) : time() }}">
        @endif
        <script src="{{ asset('js/app.js') }}" defer></script>
    </head>
    <body class="gwm-app gwm-recovery-page">
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
