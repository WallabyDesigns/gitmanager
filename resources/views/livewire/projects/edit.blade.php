<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @include('livewire.projects.partials.tabs')
        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                    Edit Project
                </h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Update the configuration for this project.</p>

                @include('livewire.projects.partials.form', [
                    'submitAction' => 'save',
                    'submitLabel' => 'Update Project',
                    'cancelUrl' => route('projects.index'),
                ])
            </div>
        </div>
    </div>
</div>
