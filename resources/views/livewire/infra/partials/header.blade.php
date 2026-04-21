<div class="flex items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <div>
            <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ $title ?? 'Infrastructure' }}</h2>
            @if (!empty($subtitle))
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
            @endif
        </div>
    </div>
    @isset($actions)
        <div class="flex items-center gap-2 shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>
