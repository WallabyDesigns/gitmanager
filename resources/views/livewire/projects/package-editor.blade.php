<div class="space-y-6">
    <div>
        <h3 class="text-lg font-semibold text-slate-100">{{ __('Package Files') }}</h3>
        <p class="mt-1 text-sm text-slate-400">{{ __('Edit package manifests. Saving runs a re-install; errors revert the file automatically.') }}</p>
    </div>

    @if ($availableFiles === [])
        <div class="rounded-lg border border-slate-800 p-6 text-sm text-slate-500">
            {{ __('No package files found in the project directory.') }}
        </div>
    @else
        @if (count($availableFiles) > 1)
            <div class="flex gap-2">
                @foreach ($availableFiles as $filename)
                    <button
                        type="button"
                        wire:click="selectFile('{{ $filename }}')"
                        class="px-3 py-1.5 text-xs rounded-md {{ $selectedFile === $filename ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-300 hover:text-slate-100' }}"
                    >
                        {{ $filename }}
                    </button>
                @endforeach
            </div>
        @endif

        @if ($error)
            <div class="rounded-lg border border-rose-500/40 bg-rose-500/10 p-3 text-sm text-rose-300">
                {{ $error }}
            </div>
        @endif

        @if ($success)
            <div class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-300">
                {{ $success }}
            </div>
        @endif

        @if ($queueItemId)
            <div wire:poll.3s="checkInstall"></div>
            <div class="flex items-center gap-2 text-sm text-indigo-300">
                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                {{ __('Running install…') }}
            </div>
        @endif

        @if ($installOutput)
            <div class="rounded-lg border border-slate-700 p-3">
                <p class="text-xs font-semibold text-slate-400 mb-2">{{ __('Install output') }}</p>
                <pre class="text-xs text-slate-300 overflow-x-auto whitespace-pre-wrap max-h-64">{{ $installOutput }}</pre>
            </div>
        @endif

        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <label for="package-editor-textarea" class="text-sm text-slate-400">
                    {{ $selectedFile }}
                </label>
            </div>
            <textarea
                id="package-editor-textarea"
                wire:model="fileContent"
                rows="24"
                spellcheck="false"
                class="w-full rounded-lg border border-slate-700 bg-slate-950 px-4 py-3 font-mono text-xs text-slate-100 focus:border-indigo-500 focus:ring-indigo-500 resize-y"
            ></textarea>
        </div>

        <div class="flex items-center gap-3">
            <button
                type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                @if ($queueItemId) disabled @endif
                class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <x-loading-spinner target="save" />
                {{ __('Save & Install') }}
            </button>
            <p class="text-xs text-slate-500">{{ __('Changes will trigger a re-install. The file reverts automatically if install fails.') }}</p>
        </div>
    @endif
</div>
