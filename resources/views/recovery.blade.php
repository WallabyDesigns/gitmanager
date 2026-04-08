<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Recovery | {{ config('app.name', 'Git Web Manager') }}</title>

        @php
            $viteManifest = public_path('build/manifest.json');
            $viteHot = public_path('hot');
            $viteReady = file_exists($viteManifest) || file_exists($viteHot);
        @endphp
        @if ($viteReady)
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="bg-slate-950 text-slate-100 min-h-screen">
        @include('partials.recovery-panel', [
            'forceVisible' => true,
            'overlay' => false,
            'status' => $status ?? null,
            'output' => $log ?? '',
        ])
    </body>
</html>
