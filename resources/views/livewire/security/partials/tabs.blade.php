@php
    $isAdmin = auth()->user()?->isAdmin() ?? false;
    $activeTabClass = 'border-indigo-500 text-white';
    $idleTabClass = 'border-transparent text-slate-400 hover:text-slate-200';
    $securityTab = $securityTab ?? 'security';
@endphp

<div class="min-w-0">
    <nav class="flex flex-wrap gap-1 border-b border-slate-800" aria-label="{{ __('Security navigation') }}">
        <a href="{{ route('security.index') }}"
           class="px-3 py-2 text-sm border-b-2 -mb-px {{ $securityTab === 'security' ? $activeTabClass : $idleTabClass }}">
            {{ __('Security') }}
        </a>
        @if ($isAdmin)
            <a href="{{ route('security.settings') }}"
               class="px-3 py-2 text-sm border-b-2 -mb-px {{ $securityTab === 'settings' ? $activeTabClass : $idleTabClass }}">
                {{ __('Settings') }}
            </a>
        @endif
    </nav>
</div>
