@if ($showInspect)
    @php
        $inspect = is_array($inspectData ?? null) ? $inspectData : [];

        $summaryRows = [];
        $sections = [];

        foreach ($inspect as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $summaryRows[] = [
                    'label' => (string) $key,
                    'value' => $value,
                ];
                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            if ($value === []) {
                continue;
            }

            $rows = [];

            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                $index = 1;
                foreach ($value as $item) {
                    $rows[] = [
                        'label' => '#'.$index,
                        'value' => $item,
                    ];
                    $index++;
                }
            } else {
                foreach ($value as $subKey => $subValue) {
                    $rows[] = [
                        'label' => (string) $subKey,
                        'value' => $subValue,
                    ];
                }
            }

            if ($rows !== []) {
                $sections[] = [
                    'title' => (string) $key,
                    'rows' => $rows,
                ];
            }
        }

        $renderInspectValue = static function ($value): string {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if ($value === null) {
                return 'null';
            }

            if (is_scalar($value)) {
                $text = trim((string) $value);
                return $text === '' ? '—' : $text;
            }

            if (is_array($value) && $value === []) {
                return '[]';
            }

            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[complex value]';
        };
    @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showInspect', false)">
        <div class="w-full max-w-4xl rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-2xl flex flex-col max-h-[90vh]">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 px-6 py-4 shrink-0">
                <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Inspect Details') }}</h3>
                <button wire:click="$set('showInspect', false)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="overflow-y-auto flex-1 p-6 space-y-4">
                @if ($summaryRows !== [])
                    <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 overflow-hidden">
                        <div class="bg-slate-50 dark:bg-slate-800/50 px-4 py-2 text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Summary') }}</div>
                        <div class="divide-y divide-slate-200/60 dark:divide-slate-800">
                            @foreach ($summaryRows as $row)
                                <div class="px-4 py-2.5 text-sm grid grid-cols-[180px,1fr] gap-3">
                                    <div class="text-slate-500 dark:text-slate-400">{{ $row['label'] }}</div>
                                    <div class="text-slate-800 dark:text-slate-100 break-all">{{ $renderInspectValue($row['value']) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @foreach ($sections as $section)
                    <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 overflow-hidden">
                        <div class="bg-slate-50 dark:bg-slate-800/50 px-4 py-2 text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            {{ $section['title'] }}
                        </div>
                        <div class="divide-y divide-slate-200/60 dark:divide-slate-800">
                            @foreach ($section['rows'] as $row)
                                <div class="px-4 py-2.5 text-sm grid grid-cols-[180px,1fr] gap-3">
                                    <div class="text-slate-500 dark:text-slate-400">{{ $row['label'] }}</div>
                                    <div class="text-slate-800 dark:text-slate-100">
                                        @if (is_array($row['value']) || is_object($row['value']))
                                            <pre class="text-xs font-mono text-slate-100 bg-slate-900 border border-slate-700 rounded p-2 whitespace-pre-wrap break-all">{{ $renderInspectValue($row['value']) }}</pre>
                                        @else
                                            <span class="break-all">{{ $renderInspectValue($row['value']) }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <details class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-3">
                    <summary class="cursor-pointer text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Raw JSON') }}</summary>
                    <pre class="mt-3 text-xs font-mono text-slate-100 bg-slate-900 border border-slate-700 rounded-lg p-3 whitespace-pre-wrap break-all">{{ json_encode($inspect, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
            </div>
        </div>
    </div>
@endif
