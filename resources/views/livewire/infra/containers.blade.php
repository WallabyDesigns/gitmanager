<div wire:init="loadPageData">
    @if ($enterpriseInstalled)
        <livewire:gwm-enterprise.containers :section="$initialSection" />
    @else
        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

                @include('livewire.infra.partials.tabs')

                {{-- Flash --}}
                @if ($flashMessage)
                    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                         class="flex items-center gap-3 rounded-lg border px-4 py-3 text-sm font-medium {{ $flashType === 'success' ? 'border-emerald-700 bg-emerald-950/40 text-emerald-300' : 'border-rose-700 bg-rose-950/40 text-rose-300' }}">
                        @if ($flashType === 'success')
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @else
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        @endif
                        {{ $flashMessage }}
                        <button @click="show = false" class="ml-auto opacity-60 hover:opacity-100">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                @endif

                {{-- Docker unavailable notice --}}
                @if (! $dockerAvailable && $initialSection !== 'templates')
                    <div class="rounded-xl border border-amber-700/50 bg-amber-950/30 p-6 flex items-start gap-4">
                        <svg class="h-6 w-6 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                        <div>
                            <p class="font-semibold text-amber-200">{{ __('Docker not detected') }}</p>
                            <p class="mt-1 text-sm text-amber-300">{{ __('Make sure Docker is installed and running on this server, and that the :var env variable points to the correct binary (default: :default).', ['var' => '<code class="font-mono bg-amber-900/50 px-1 rounded">GWM_DOCKER_BINARY</code>', 'default' => '<code class="font-mono bg-amber-900/50 px-1 rounded">docker</code>']) }}</p>
                        </div>
                    </div>
                @endif

                {{-- ─── Overview ─────────────────────────────────────────────────── --}}
                @if ($initialSection === 'overview')
                    @include('livewire.infra.partials.section-overview')

                {{-- ─── Containers ───────────────────────────────────────────────── --}}
                @elseif ($initialSection === 'containers')
                    @include('livewire.infra.partials.section-containers')

                {{-- ─── Images ───────────────────────────────────────────────────── --}}
                @elseif ($initialSection === 'images')
                    @include('livewire.infra.partials.section-images')

                {{-- ─── Volumes ──────────────────────────────────────────────────── --}}
                @elseif ($initialSection === 'volumes')
                    @include('livewire.infra.partials.section-volumes')

                {{-- ─── Networks ─────────────────────────────────────────────────── --}}
                @elseif ($initialSection === 'networks')
                    @include('livewire.infra.partials.section-networks')

                {{-- ─── Swarm ────────────────────────────────────────────────────── --}}
                @elseif ($initialSection === 'swarm')
                    @include('livewire.infra.partials.section-swarm')

                {{-- ─── Templates ────────────────────────────────────────────────── --}}
                @elseif ($initialSection === 'templates')
                    @include('livewire.infra.partials.section-templates')

                {{-- ─── Databases ────────────────────────────────────────────────── --}}
                @elseif ($initialSection === 'databases')
                    @include('livewire.infra.partials.section-databases')

                @else
                    @include('livewire.infra.partials.section-overview')
                @endif

            </div>
        </div>

        {{-- ─── Shared Modals ─────────────────────────────────────────────────── --}}
        @include('livewire.infra.partials.modal-inspect')
        @include('livewire.infra.partials.modal-logs')
        @include('livewire.infra.partials.modal-create-container')
        @include('livewire.infra.partials.modal-edit-container')
    @endif
</div>
