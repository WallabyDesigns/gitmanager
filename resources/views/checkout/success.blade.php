<x-app-layout>
    <div class="py-16">
        <div class="max-w-xl mx-auto px-4 sm:px-6 lg:px-8 text-center space-y-6">
            <div class="inline-flex items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-500/10 p-4">
                <svg class="h-10 w-10 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>

            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ __('Purchase Complete') }}</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Thank you for upgrading to Enterprise Edition.') }}
                    {{ __('Your license key will be emailed to you shortly.') }}
                </p>
            </div>

            <div class="rounded-xl border border-amber-300/60 bg-amber-50/70 dark:border-amber-500/30 dark:bg-amber-500/10 p-5 text-left space-y-2">
                <div class="text-sm font-semibold text-amber-800 dark:text-amber-200">{{ __('Next steps') }}</div>
                <ol class="list-decimal list-inside text-xs text-amber-700 dark:text-amber-300 space-y-1">
                    <li>{{ __('Check your email for a license key from Wallaby Designs.') }}</li>
                    <li>{{ __('Go to System → Settings and enter your license key.') }}</li>
                    <li>{{ __('Click Verify License Now to activate Enterprise Edition.') }}</li>
                </ol>
            </div>

            <div class="flex flex-wrap justify-center gap-3">
                <a href="{{ route('system.licensing') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    {{ __('Go to System Settings') }}
                </a>
                <a href="{{ route('projects.index') }}" class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                    {{ __('Back to projects') }}
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
