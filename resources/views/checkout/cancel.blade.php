<x-app-layout>
    <div class="py-16">
        <div class="max-w-xl mx-auto px-4 sm:px-6 lg:px-8 text-center space-y-6">
            <div class="inline-flex items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800 p-4">
                <svg class="h-10 w-10 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </div>

            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ __('Checkout Cancelled') }}</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('No payment was taken. You can upgrade to Enterprise Edition any time.') }}
                </p>
            </div>

            <div class="flex flex-wrap justify-center gap-3">
                <button
                    type="button"
                    onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Edition' } }));"
                    class="inline-flex items-center rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-400"
                >
                    {{ __('Try Again') }}
                </button>
                <a href="{{ route('projects.index') }}" class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                    {{ __('Back to projects') }}
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
