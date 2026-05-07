@php
    $isRunning = ($container['State'] ?? '') === 'running';
    $cName = ltrim((string) ($container['Names'] ?? $container['ID'] ?? '?'), '/');
@endphp

<div class="flex items-center gap-3 rounded-lg border px-4 py-2.5 border-slate-800 bg-slate-900">
    <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $isRunning ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
    <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-medium text-slate-200">{{ $cName }}</p>
        <p class="truncate text-xs text-slate-500">{{ $container['Image'] ?? '' }}</p>
    </div>
    <span class="shrink-0 text-xs font-medium capitalize {{ $isRunning ? 'text-emerald-400' : 'text-slate-400' }}">
        {{ $container['State'] ?? 'unknown' }}
    </span>
</div>
