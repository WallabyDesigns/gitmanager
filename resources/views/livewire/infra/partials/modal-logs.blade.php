@if ($showLogs)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showLogs', false)">
        <div class="w-full max-w-4xl rounded-2xl border border-slate-700 bg-slate-950 shadow-2xl flex flex-col max-h-[85vh]">
            <div class="flex items-center justify-between border-b border-slate-800 px-6 py-4 shrink-0">
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <h3 class="font-semibold text-slate-100">{{ __('Logs') }} — {{ $logsTarget }}</h3>
                </div>
                <div class="flex items-center gap-3">
                    <button wire:click="viewLogs('{{ $logsTarget }}')" class="text-xs text-slate-400 hover:text-slate-200 flex items-center gap-1">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('Refresh') }}
                    </button>
                    <button wire:click="$set('showLogs', false)" class="text-slate-400 hover:text-slate-200">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <div class="overflow-y-auto flex-1 p-6">
                <pre class="text-xs font-mono text-emerald-300 whitespace-pre-wrap break-all leading-relaxed">{{ $logsData ?: '(no output)' }}</pre>
            </div>
        </div>
    </div>
@endif
