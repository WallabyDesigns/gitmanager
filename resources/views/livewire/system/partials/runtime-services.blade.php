<section class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-slate-100">{{ __('Runtime Services') }}</h2>
        <p class="mt-1 text-sm text-slate-400">{{ __('Manage optional application runtimes.') }}</p>
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
        <div class="bg-slate-900 border border-slate-800 rounded-lg p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-100">{{ __('Octane Runtime') }}</h3>
                    <p class="mt-1 text-sm text-slate-400">{{ __('Managed through the local Docker Compose Octane profile when Docker is available.') }}</p>
                </div>
                <span class="shrink-0 rounded-full px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ ($octaneStatus['running'] ?? false) ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-500/20 text-slate-200' }}">
                    {{ ($octaneStatus['running'] ?? false) ? __('Running') : __('Stopped') }}
                </span>
            </div>
            <div class="text-xs text-slate-400 space-y-1">
                <div>{{ __('Endpoint') }}: <span class="font-mono text-slate-200">{{ $octaneStatus['url'] ?? '' }}</span></div>
                <div>{{ __('Docker') }}: <span class="{{ ($octaneStatus['docker_available'] ?? false) ? 'text-emerald-300' : 'text-rose-300' }}">{{ ($octaneStatus['docker_available'] ?? false) ? __('Available') : __('Unavailable') }}</span></div>
                <div>{{ __('Compose') }}: <span class="{{ ($octaneStatus['compose_available'] ?? false) ? 'text-emerald-300' : 'text-rose-300' }}">{{ ($octaneStatus['compose_available'] ?? false) ? __('Available') : __('Unavailable') }}</span></div>
            </div>
            @if (! empty($octaneStatus['message']))
                <p class="rounded-md border border-slate-800 bg-slate-950/40 px-3 py-2 text-xs text-slate-300">{{ $octaneStatus['message'] }}</p>
            @endif
            <div class="flex flex-wrap gap-2">
                @if ($octaneStatus['running'] ?? false)
                    <button type="button" wire:click="restartOctane" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center"><x-loading-spinner target="restartOctane" />{{ __('Restart Octane') }}</button>
                    <button type="button" wire:click="stopOctane" class="px-3 py-2 text-xs rounded-md border border-rose-500/40 text-rose-200 hover:text-white inline-flex items-center"><x-loading-spinner target="stopOctane" />{{ __('Stop Octane') }}</button>
                @else
                    <button type="button" wire:click="startOctane" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center"><x-loading-spinner target="startOctane" />{{ __('Start Octane') }}</button>
                @endif
            </div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-lg p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div><h3 class="text-base font-semibold text-slate-100">{{ __('Reverb Real-Time Server') }}</h3><p class="mt-1 text-sm text-slate-400">{{ __('Optional WebSocket broadcasting for live queue, deployment, and audit updates.') }}</p></div>
                <span class="shrink-0 rounded-full px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $reverbStatus['configured'] ? 'bg-emerald-500/20 text-emerald-200' : ($reverbStatus['installed'] ? 'bg-amber-500/20 text-amber-200' : 'bg-slate-500/20 text-slate-200') }}">{{ $reverbStatus['configured'] ? __('Active') : ($reverbStatus['installed'] ? __('Installed') : __('Not bundled')) }}</span>
            </div>
            <div class="text-xs text-slate-400">{{ $reverbStatus['message'] }}</div>
            @if ($reverbStatus['version'])<div class="text-xs text-slate-400">{{ __('Version') }}: <span class="font-mono text-slate-200">{{ $reverbStatus['version'] }}</span></div>@endif
            @if ($reverbStatus['installed'])
                <div class="text-xs text-slate-400">{{ __('Configuration') }}: <span class="{{ $reverbStatus['credentials_configured'] ? 'text-emerald-300' : 'text-amber-300' }}">{{ $reverbStatus['credentials_configured'] ? __('Ready') : __('Credentials required') }}</span></div>
                @if (! $reverbStatus['configured'])
                    <button type="button" wire:click="activateReverb" wire:loading.attr="disabled" class="px-3 py-2 text-xs rounded-md border border-emerald-500/40 text-emerald-200 hover:text-white inline-flex items-center"><x-loading-spinner target="activateReverb" />{{ __('Activate Reverb') }}</button>
                @endif
                @if (! $reverbStatus['credentials_configured'])
                    <a href="{{ route('system.environment') }}" class="inline-flex text-xs text-indigo-300 hover:text-indigo-200">{{ __('Open Environment Config') }}</a>
                @endif
            @endif
            <p class="text-xs text-slate-500">{{ __('Reverb will remain optional with polling as a fallback for shared hosting. Run php artisan reverb:start under a persistent process manager after activation.') }}</p>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-lg p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div><h3 class="text-base font-semibold text-slate-100">{{ __('Rust Operations Executor') }}</h3><p class="mt-1 text-sm text-slate-400">{{ __('Optional sidecar for durable deployment, update, and audit execution.') }}</p></div>
                <span class="shrink-0 rounded-full px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ ($rustExecutorStatus['running'] ?? false) ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-500/20 text-slate-200' }}">{{ ($rustExecutorStatus['running'] ?? false) ? __('Running') : (($rustExecutorStatus['state'] ?? '') === 'not_created' ? __('Not installed') : __('Stopped')) }}</span>
            </div>
            <div class="text-xs text-slate-400">{{ $rustExecutorStatus['message'] }}</div>
            <div class="text-xs text-slate-400 space-y-1">
                <div>{{ __('Docker') }}: <span class="{{ ($rustExecutorStatus['docker_available'] ?? false) ? 'text-emerald-300' : 'text-rose-300' }}">{{ ($rustExecutorStatus['docker_available'] ?? false) ? __('Available') : __('Unavailable') }}</span></div>
                <div>{{ __('Compose') }}: <span class="{{ ($rustExecutorStatus['compose_available'] ?? false) ? 'text-emerald-300' : 'text-rose-300' }}">{{ ($rustExecutorStatus['compose_available'] ?? false) ? __('Available') : __('Unavailable') }}</span></div>
                <div>{{ __('Internal endpoint') }}: <span class="font-mono text-slate-200">{{ $rustExecutorStatus['endpoint'] ?? '' }}</span></div>
            </div>
            <p class="text-xs text-slate-500">{{ __('The executor is optional. It is installed locally through Docker and does not replace PHP workers until executor job claiming is enabled.') }}</p>
            <div class="flex flex-wrap gap-2">
                @if ($rustExecutorStatus['running'] ?? false)
                    <button type="button" wire:click="restartRustExecutor" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center"><x-loading-spinner target="restartRustExecutor" />{{ __('Restart Executor') }}</button>
                    <button type="button" wire:click="stopRustExecutor" class="px-3 py-2 text-xs rounded-md border border-rose-500/40 text-rose-200 hover:text-white inline-flex items-center"><x-loading-spinner target="stopRustExecutor" />{{ __('Stop Executor') }}</button>
                @else
                    <button type="button" wire:click="startRustExecutor" class="px-3 py-2 text-xs rounded-md border border-slate-700 text-slate-200 hover:text-white inline-flex items-center"><x-loading-spinner target="startRustExecutor" />{{ __('Install Executor') }}</button>
                @endif
            </div>
        </div>
    </div>
</section>
