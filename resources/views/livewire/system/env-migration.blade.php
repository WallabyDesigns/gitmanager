<div class="min-h-screen bg-slate-950 flex items-center justify-center p-4">
    <div class="w-full max-w-2xl">

        {{-- Header --}}
        <div class="mb-8 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-amber-500/10 border border-amber-500/30 mb-4">
                <svg class="h-6 w-6 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />
                </svg>
            </div>
            <h1 class="text-xl font-bold text-white">Environment Setup</h1>
            <p class="mt-1 text-sm text-slate-400">A few new configuration values were added in this update.</p>
        </div>

        {{-- Step indicators --}}
        <div class="flex items-center justify-center gap-3 mb-8">
            @foreach ([1 => 'Config', 2 => 'Done'] as $n => $label)
                <div class="flex items-center gap-1.5">
                    <div class="flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold
                        {{ $step >= $n ? 'bg-indigo-500 text-white' : 'bg-slate-800 text-slate-500' }}">
                        @if ($step > $n)
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        @else
                            {{ $n }}
                        @endif
                    </div>
                    <span class="text-xs {{ $step >= $n ? 'text-slate-200' : 'text-slate-500' }}">{{ $label }}</span>
                </div>
                @if ($n < 2)
                    <div class="h-px w-8 {{ $step > $n ? 'bg-indigo-500' : 'bg-slate-700' }}"></div>
                @endif
            @endforeach
        </div>

        {{-- Step 1: Fill missing keys --}}
        @if ($step === 1)
            <div class="rounded-2xl border border-slate-800 bg-slate-900 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-800">
                    <h2 class="text-sm font-semibold text-white">Missing Environment Variables</h2>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ count($missingKeys) }} variable{{ count($missingKeys) === 1 ? '' : 's' }} were added since your last install.
                        Set values below or leave blank to use the default.
                    </p>
                </div>
                <div class="divide-y divide-slate-800">
                    @foreach ($missingKeys as $key => $meta)
                        <div class="px-6 py-4 space-y-2">
                            <label for="env-key-{{ $loop->index }}" class="block text-xs font-mono font-semibold text-indigo-300">{{ $key }}</label>
                            @if ($meta['description'])
                                <p class="text-xs text-slate-500">{{ $meta['description'] }}</p>
                            @endif
                            <input
                                id="env-key-{{ $loop->index }}"
                                wire:model="values.{{ $key }}"
                                type="{{ str_contains(strtolower($key), 'password') || str_contains(strtolower($key), 'secret') || str_contains(strtolower($key), 'key') ? 'password' : 'text' }}"
                                placeholder="{{ $meta['default'] !== '' ? $meta['default'] : 'Leave blank for default' }}"
                                class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 font-mono placeholder:text-slate-600 focus:border-indigo-500 focus:outline-none"
                            >
                        </div>
                    @endforeach
                </div>
                <div class="px-6 py-4 bg-slate-800/30 flex items-center justify-between gap-4">
                    <button wire:click="dismiss" class="text-xs text-slate-500 hover:text-slate-300 transition">
                        Skip for now
                    </button>
                    <button wire:click="saveValues" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white transition">
                        <x-loading-spinner target="saveValues" />
                        Save &amp; Continue
                    </button>
                </div>
            </div>
        @endif

        {{-- Step 2: Done --}}
        @if ($step === 2)
            <div class="rounded-2xl border border-emerald-800/50 bg-emerald-500/5 overflow-hidden text-center">
                <div class="px-6 py-10 space-y-4">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-500/10 border border-emerald-500/30">
                        <svg class="h-7 w-7 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold text-white">Setup complete</h2>
                        <p class="mt-1 text-sm text-slate-400">Your environment is up to date. You can adjust any values later in <strong class="text-slate-200">System → Environment Config</strong>.</p>
                    </div>
                    <button wire:click="finish" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-5 py-2.5 text-sm font-semibold text-white transition">
                        Go to Settings
                    </button>
                </div>
            </div>
        @endif

    </div>
</div>
