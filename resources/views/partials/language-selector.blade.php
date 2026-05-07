@props([
    'floating' => false,
])

@php
    $languageOptions = \App\Support\LanguageOptions::all();
    $currentLocale = \App\Support\LanguageOptions::normalize(auth()->user()?->locale ?? session('locale') ?? app()->getLocale());
    $currentLanguage = \App\Support\LanguageOptions::label($currentLocale);
@endphp

@if ($floating)
    <button
        type="button"
        class="fixed right-4 top-4 z-[1200] inline-flex h-9 w-9 items-center justify-center rounded-lg border shadow-sm transition focus:outline-none border-slate-700 bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white"
        aria-label="{{ __('Choose language') }}"
        title="{{ __('Choose language') }}"
        data-gwm-language-open
    >
        <svg class="h-5 w-5" viewBox="0 0 16 16" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M4 0H6V2H10V4H8.86807C8.57073 5.66996 7.78574 7.17117 6.6656 8.35112C7.46567 8.73941 8.35737 8.96842 9.29948 8.99697L10.2735 6H12.7265L15.9765 16H13.8735L13.2235 14H9.77647L9.12647 16H7.0235L8.66176 10.9592C7.32639 10.8285 6.08165 10.3888 4.99999 9.71246C3.69496 10.5284 2.15255 11 0.5 11H0V9H0.5C1.5161 9 2.47775 8.76685 3.33437 8.35112C2.68381 7.66582 2.14629 6.87215 1.75171 6H4.02179C4.30023 6.43491 4.62904 6.83446 4.99999 7.19044C5.88743 6.33881 6.53369 5.23777 6.82607 4H0V2H4V0ZM12.5735 12L11.5 8.69688L10.4265 12H12.5735Z" />
        </svg>
    </button>
@endif

<div id="gwm-language-modal" class="fixed inset-0 z-[1400] hidden items-center justify-center px-4 py-6" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" data-gwm-language-close></div>
    <div class="relative w-full max-w-md rounded-xl border p-6 shadow-2xl border-slate-800 bg-slate-900">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-slate-100">{{ __('Language') }}</h3>
                <p class="mt-1 text-sm text-slate-400">{{ __('Current language: :language', ['language' => $currentLanguage]) }}</p>
            </div>
            <button type="button" class="rounded-md p-2 transition text-slate-300 hover:bg-slate-800 hover:text-slate-100" aria-label="{{ __('Close language selector') }}" data-gwm-language-close>
                <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ \Illuminate\Support\Facades\Route::has('language.update') ? route('language.update') : url('/language') }}" class="mt-5 space-y-4">
            @csrf
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-400" for="gwm-language-select">{{ __('Display Language') }}</label>
            <select
                id="gwm-language-select"
                name="locale"
                class="w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border-slate-700 bg-slate-950 text-slate-100"
            >
                @foreach ($languageOptions as $locale => $label)
                    <option value="{{ $locale }}" @selected($currentLocale === $locale)>{{ $label }}</option>
                @endforeach
            </select>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <button type="button" class="rounded-md border px-4 py-2 text-sm transition border-slate-700 text-slate-200 hover:text-white" data-gwm-language-close>
                    {{ __('Cancel') }}
                </button>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500">
                    {{ __('Save Language') }}
                </button>
            </div>
        </form>
    </div>
</div>

<script data-navigate-once="true">
    (() => {
        const openModal = () => {
            const modal = document.getElementById('gwm-language-modal');
            if (!modal) {
                return;
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');
            window.setTimeout(() => document.getElementById('gwm-language-select')?.focus(), 50);
        };

        const closeModal = () => {
            const modal = document.getElementById('gwm-language-modal');
            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
        };

        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-gwm-language-open]')) {
                openModal();
            }

            if (event.target.closest('[data-gwm-language-close]')) {
                closeModal();
            }
        });

        window.addEventListener('gwm-open-language-modal', openModal);
        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
    })();
</script>
