<x-app-layout>
    <div class="py-16">
        <div class="max-w-xl mx-auto px-4 sm:px-6 lg:px-8 text-center space-y-6">
            <div class="inline-flex items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-500/10 p-4">
                <svg class="h-10 w-10 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>

<div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ __('Testing Checkout Flow') }}</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Live Stripe checkout is not configured on this installation.') }}
                    {{ __('Use this simulated flow to test Enterprise checkout UX and redirects.') }}
                </p>
            </div>

            <div class="rounded-xl border border-indigo-300/60 bg-indigo-50/70 dark:border-indigo-500/30 dark:bg-indigo-500/10 p-5 text-left space-y-2">
                <div class="text-sm font-semibold text-indigo-800 dark:text-indigo-200">{{ __('Simulation Mode') }}</div>
                <ul class="list-disc list-inside text-xs text-indigo-700 dark:text-indigo-300 space-y-1">
                    <li>{{ __('This mode is only enabled for trusted APP_KEY test installs.') }}</li>
                    <li>{{ __('No real payment is created.') }}</li>
                    <li>{{ __('Simulate Success will run the normal success redirect path.') }}</li>
                </ul>
            </div>

            <div class="flex flex-wrap justify-center gap-3">
                @if ($liveCheckoutReady)
                    <form method="POST" action="{{ route('checkout.enterprise') }}">
                        @csrf
                        <input type="hidden" name="force_live" value="1">
                        <input type="hidden" name="locale" value="{{ \App\Support\LanguageOptions::normalize(auth()->user()?->locale ?? session('locale') ?? app()->getLocale()) }}">
                        <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                            {{ __('Open Live Stripe Checkout') }}
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('checkout.enterprise.testing.complete') }}">
                    @csrf
                    <input type="hidden" name="action" value="success">
                    <button type="submit" class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                        {{ __('Simulate Success') }}
                    </button>
                </form>

                <form method="POST" action="{{ route('checkout.enterprise.testing.complete') }}">
                    @csrf
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                        {{ __('Simulate Cancel') }}
                    </button>
                </form>
            </div>

            <div class="rounded-xl border border-indigo-300/60 bg-indigo-50/70 dark:border-indigo-500/30 dark:bg-indigo-500/10 p-5 text-left space-y-2">
<div class="text-sm font-semibold text-indigo-800 dark:text-indigo-200">{{ __('Simulation Mode') }}</div>
                <ul class="list-disc list-inside text-xs text-indigo-700 dark:text-indigo-300 space-y-1">
                    <li>{{ __('This mode is only enabled for trusted APP_KEY test installs.') }}</li>
                    <li>{{ __('No real payment is created.') }}</li>
                    <li>{{ __('"Simulate Success" will run the normal success redirect path.') }}</li>
                </ul>
            </div>

            <div class="flex flex-wrap justify-center gap-3">
                @if ($liveCheckoutReady)
                    <form method="POST" action="{{ route('checkout.enterprise') }}">
                        @csrf
                        <input type="hidden" name="force_live" value="1">
                        <input type="hidden" name="locale" value="{{ \App\Support\LanguageOptions::normalize(auth()->user()?->locale ?? session('locale') ?? app()->getLocale()) }}">
                        <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                            Open Live Stripe Checkout
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('checkout.enterprise.testing.complete') }}">
                    @csrf
                    <input type="hidden" name="action" value="success">
                    <button type="submit" class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                        Simulate Success
                    </button>
                </form>

                <form method="POST" action="{{ route('checkout.enterprise.testing.complete') }}">
                    @csrf
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                        Simulate Cancel
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
