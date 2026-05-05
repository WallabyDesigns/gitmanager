<div>
    {{-- Mobile: dropdown (hidden on sm+) --}}
    <div class="sm:hidden relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
        <button
            type="button"
            @click="open = !open"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-200/70 dark:border-slate-700 bg-white dark:bg-slate-900/60 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600 transition"
            aria-haspopup="true"
            :aria-expanded="open"
        >
            <span>
                @if ($tab === 'form') {{ __('Create Workflow') }}
                @elseif ($tab === 'test') {{ __('Test Delivery') }}
                @else {{ __('Current Workflows') }}
                @endif
            </span>
            <svg class="h-4 w-4 text-slate-400 transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute left-0 right-0 top-full mt-1 z-50 rounded-lg border border-slate-200/70 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-lg overflow-hidden"
            role="menu"
        >
            <button
                type="button"
                wire:click="setTab('list')"
                @click="open = false"
                class="flex w-full items-center px-4 py-3 text-sm transition {{ $tab === 'list' ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 font-medium' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}"
                role="menuitem"
            >
                {{ __('Current Workflows') }}
            </button>
            <button
                type="button"
                wire:click="setTab('form')"
                @click="open = false"
                class="flex w-full items-center px-4 py-3 text-sm transition border-t border-slate-100 dark:border-slate-800 {{ $tab === 'form' ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 font-medium' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}"
                role="menuitem"
            >
                {{ __('Create Workflow') }}
            </button>
            <button
                type="button"
                wire:click="setTab('test')"
                @click="open = false"
                class="flex w-full items-center px-4 py-3 text-sm transition border-t border-slate-100 dark:border-slate-800 {{ $tab === 'test' ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 font-medium' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}"
                role="menuitem"
            >
                {{ __('Test Delivery') }}
            </button>
        </div>
    </div>

    {{-- Desktop: horizontal tabs (hidden on mobile) --}}
    <div class="hidden sm:flex flex-wrap gap-2 border-b border-slate-200/70 dark:border-slate-800">
        <button type="button"
                wire:click="setTab('list')"
                class="px-3 py-2 text-sm border-b-2 {{ $tab === 'list' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
            {{ __('Current Workflows') }}
        </button>
        <button type="button"
                wire:click="setTab('form')"
                class="px-3 py-2 text-sm border-b-2 {{ $tab === 'form' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
            {{ __('Create Workflow') }}
        </button>
        <button type="button"
                wire:click="setTab('test')"
                class="px-3 py-2 text-sm border-b-2 {{ $tab === 'test' ? 'border-indigo-500 text-slate-900 dark:text-slate-100' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
            {{ __('Test Delivery') }}
        </button>
    </div>
</div>
