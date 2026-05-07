<div class="space-y-2">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-100">{{ __('Action Center') }}</h2>
            <p class="text-sm text-slate-400">{{ __('Review project security alerts, dependency issues, and other actionable items.') }}</p>
        </div>
        <a href="{{ route('projects.index') }}" class="text-sm flex items-center text-slate-400 hover:text-slate-200">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            {{ __('Back to projects') }}
        </a>
    </div>
</div>
