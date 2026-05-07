<nav class="-mx-3 flex flex-1 justify-end">
    @auth
        <a
            href="{{ url('/projects') }}"
            class="rounded-md px-3 py-2 ring-1 ring-transparent transition focus:outline-none text-white hover:text-white/80 focus-visible:ring-white"
        >
            {{ __('Projects') }}
        </a>
    @else
        <a
            href="{{ route('login') }}"
            class="rounded-md px-3 py-2 ring-1 ring-transparent transition focus:outline-none text-white hover:text-white/80 focus-visible:ring-white"
        >
            {{ __('Log in') }}
        </a>

        @php($registrationOpen = Route::has('register') && ! \App\Models\User::query()->exists())
        @if ($registrationOpen)
            <a
                href="{{ route('register') }}"
                class="rounded-md px-3 py-2 ring-1 ring-transparent transition focus:outline-none text-white hover:text-white/80 focus-visible:ring-white"
            >
                {{ __('Register') }}
            </a>
        @endif
    @endauth
</nav>
