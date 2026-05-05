@if (! $mailConfigured)
    <div class="rounded-xl border border-amber-300/60 p-4 text-sm text-amber-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="text-xs uppercase tracking-wide text-amber-300">{{ __('Email Not Configured') }}</div>
            <div class="text-sm text-amber-100">{{ __('Email features will not work until SMTP settings are configured.') }}</div>
        </div>
        @if ($showMailSettingsLink ?? false)
            <a href="{{ route('system.email') }}" class="inline-flex items-center justify-center rounded-md border border-amber-300/60 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:border-white hover:text-white">
                {{ __('Go to Email Settings') }}
            </a>
        @endif
    </div>
@endif
