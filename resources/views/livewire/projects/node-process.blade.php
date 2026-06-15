<div class="space-y-6">

    {{-- Status header --}}
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            @php
                $statusColor = match($process->status) {
                    'running'  => 'bg-emerald-500/10 text-emerald-300',
                    'starting' => 'bg-indigo-500/10 text-indigo-300',
                    'crashed'  => 'bg-rose-500/10 text-rose-300',
                    default    => 'bg-slate-700/60 text-slate-400',
                };
            @endphp
            <span class="text-xs uppercase tracking-wide px-2.5 py-1 rounded-full {{ $statusColor }}">
                {{ $process->status }}
            </span>
            @if ($process->pid)
                <span class="text-xs text-slate-500 font-mono">PID {{ $process->pid }}</span>
            @endif
            @if ($process->port)
                <a href="http://localhost:{{ $process->port }}" target="_blank" rel="noopener" class="text-xs text-indigo-400 hover:text-indigo-300 font-mono">:{{ $process->port }}</a>
            @endif
            @if ($process->crash_count > 0)
                <span class="text-xs text-amber-400">{{ $process->crash_count }} crash{{ $process->crash_count !== 1 ? 'es' : '' }}</span>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            @if (! $nodeInstalled)
                <a href="{{ route('system.node') }}" class="px-3 py-2 text-xs rounded-md border border-amber-500/40 text-amber-200 hover:text-white inline-flex items-center gap-1.5">
                    Install Node.js →
                </a>
            @elseif ($process->isStopped() || $process->isCrashed())
                @if ($canRunMore)
                    <button type="button" wire:click="start" class="px-3 py-2 text-xs rounded-md border border-emerald-500/40 text-emerald-200 hover:text-white inline-flex items-center gap-1.5">
                        <x-loading-spinner target="start" />
                        {{ __('Start') }}
                    </button>
                @else
                    <span class="text-xs text-amber-400 self-center">Free limit ({{ $freeLimit }}) reached — <a href="{{ route('system.licensing') }}" class="underline">upgrade</a></span>
                @endif
            @else
                <button type="button" wire:click="restart" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center gap-1.5">
                    <x-loading-spinner target="restart" />
                    {{ __('Restart') }}
                </button>
                <button type="button" wire:click="stop" class="px-3 py-2 text-xs rounded-md border border-rose-500/40 text-rose-200 hover:text-white inline-flex items-center gap-1.5">
                    <x-loading-spinner target="stop" />
                    {{ __('Stop') }}
                </button>
            @endif
        </div>
    </div>

    @if ($process->last_started_at || $process->last_crashed_at)
        <div class="text-xs text-slate-500 space-x-4">
            @if ($process->last_started_at)
                <span>Started: {{ \App\Support\DateFormatter::forUser($process->last_started_at, 'M j, Y g:i a', '—') }}</span>
            @endif
            @if ($process->last_crashed_at)
                <span>Last crash: {{ \App\Support\DateFormatter::forUser($process->last_crashed_at, 'M j, Y g:i a', '—') }}</span>
            @endif
        </div>
    @endif

    {{-- Settings --}}
    <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4 space-y-4">
        <h4 class="text-sm font-semibold text-slate-200">{{ __('Process Settings') }}</h4>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1">
                <label class="block text-xs text-slate-400">{{ __('Start Command') }}</label>
                <input
                    type="text"
                    wire:model.defer="startCommand"
                    placeholder="npm start"
                    class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder-slate-600 focus:border-indigo-500 focus:outline-none font-mono"
                />
            </div>
            <div class="space-y-1">
                <label class="block text-xs text-slate-400">{{ __('Port (display only)') }}</label>
                <input
                    type="number"
                    wire:model.defer="port"
                    placeholder="3000"
                    min="1"
                    max="65535"
                    class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder-slate-600 focus:border-indigo-500 focus:outline-none font-mono"
                />
            </div>
        </div>

        <div class="flex items-center gap-3">
            <input
                id="node-auto-restart-{{ $process->id }}"
                type="checkbox"
                wire:model.defer="autoRestart"
                class="rounded border-slate-600 bg-slate-800 text-indigo-500 focus:ring-indigo-500"
            />
            <label for="node-auto-restart-{{ $process->id }}" class="text-sm text-slate-300">
                {{ __('Auto-restart on crash') }}
            </label>
        </div>

        <div class="flex gap-2 pt-1">
            <button type="button" wire:click="saveSettings" class="px-3 py-2 text-xs rounded-md bg-indigo-600 text-white hover:bg-indigo-500 inline-flex items-center gap-1.5">
                <x-loading-spinner target="saveSettings" />
                {{ __('Save') }}
            </button>
        </div>
    </div>

    {{-- Log viewer --}}
    <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4 space-y-3">
        <div class="flex items-center justify-between gap-2">
            <h4 class="text-sm font-semibold text-slate-200">{{ __('Process Log') }}</h4>
            <div class="flex gap-2">
                <button type="button" wire:click="$set('showLog', {{ $showLog ? 'false' : 'true' }})" class="text-xs text-indigo-400 hover:text-indigo-300">
                    {{ $showLog ? __('Hide') : __('Show') }}
                </button>
                @if ($showLog)
                    <button type="button" wire:click="clearLog" class="text-xs text-rose-400 hover:text-rose-300">
                        {{ __('Clear') }}
                    </button>
                @endif
            </div>
        </div>

        @if ($showLog)
            @if ($logLines !== '' && $logLines !== null)
                <pre
                    class="max-h-80 overflow-auto rounded-md border border-slate-800 bg-slate-900/60 p-3 text-xs text-slate-200 whitespace-pre-wrap"
                    x-data
                    x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                >{{ $logLines }}</pre>
            @else
                <p class="text-xs text-slate-500">{{ __('No log output yet.') }}</p>
            @endif
        @endif
    </div>

</div>
