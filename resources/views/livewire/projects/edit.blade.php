<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.projects.partials.tabs')
            <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-slate-100">
                        {{ __('Edit Project') }}
                    </h3>
                    <p class="text-sm text-slate-400">{{ __('Update the configuration for this project.') }}</p>

                    @include('livewire.projects.partials.form', [
                        'submitAction' => 'save',
                        'submitLabel' => __('Update Project'),
                        'cancelUrl' => route('projects.index'),
                    ])
                </div>
            </div>
        </div>
    </div>
</div>
