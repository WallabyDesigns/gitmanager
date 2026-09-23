<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            @php
                $statusClass = ($status['running'] ?? false)
                    ? 'bg-emerald-500/10 text-emerald-300'
                    : (($status['installed'] ?? false) ? 'bg-rose-500/10 text-rose-300' : 'bg-slate-700/60 text-slate-400');
            @endphp
            <span class="text-xs uppercase tracking-wide px-2.5 py-1 rounded-full {{ $statusClass }}">
                {{ $status['state'] ?? 'unknown' }}
            </span>
            <span class="font-mono text-xs text-slate-400">{{ $status['unit'] }}</span>
        </div>

        @if ($status['installed'] ?? false)
            <div class="flex flex-wrap gap-2">
                @if (!($status['running'] ?? false))
                    <button type="button" wire:click="start" class="px-3 py-2 text-xs rounded-md border border-emerald-500/40 text-emerald-200 hover:text-white inline-flex items-center gap-1.5">
                        <x-loading-spinner target="start" />{{ __('Start') }}
                    </button>
                @else
                    <button type="button" wire:click="restart" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center gap-1.5">
                        <x-loading-spinner target="restart" />{{ __('Restart') }}
                    </button>
                    <button type="button" wire:click="stop" class="px-3 py-2 text-xs rounded-md border border-rose-500/40 text-rose-200 hover:text-white inline-flex items-center gap-1.5">
                        <x-loading-spinner target="stop" />{{ __('Stop') }}
                    </button>
                @endif
            </div>
        @endif
    </div>

    <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4 space-y-2">
        <h4 class="text-sm font-semibold text-slate-200">{{ $isLarust ? __('Larust systemd service') : __('Rust systemd service') }}</h4>
        <p class="text-sm text-slate-400">{{ $status['message'] }}</p>
        @if ($isLarust)
            <p class="text-xs text-slate-500">{{ __('Larust creates this unit with xr deploy --service or xr service:install. It must be installed on the Linux machine serving the application.') }}</p>
        @else
            <p class="text-xs text-slate-500">{{ __('Set GWM_SERVICE_NAME in the project .env to inspect a specific unit (for example, my-api.service). Without it, the app name plus .service is used.') }}</p>
        @endif
    </div>

    <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4 space-y-3">
        <div class="flex items-center justify-between gap-2">
            <h4 class="text-sm font-semibold text-slate-200">{{ __('Service diagnostics') }}</h4>
            <button type="button" wire:click="$toggle('showDetails')" class="text-xs text-indigo-400 hover:text-indigo-300">
                {{ $showDetails ? __('Hide') : __('Show status and journal') }}
            </button>
        </div>
        @if ($showDetails)
            @if (($status['details'] ?? '') !== '')
                <pre class="max-h-[32rem] overflow-auto rounded-md border border-slate-800 bg-slate-900/60 p-3 text-xs text-slate-200 whitespace-pre-wrap">{{ $status['details'] }}</pre>
            @else
                <p class="text-xs text-slate-500">{{ __('No service output was returned.') }}</p>
            @endif
        @endif
    </div>
</div>
