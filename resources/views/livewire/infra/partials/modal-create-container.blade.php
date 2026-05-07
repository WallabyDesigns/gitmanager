@if ($showCreate)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showCreate', false)">
        <div class="w-full max-w-2xl rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl flex flex-col max-h-[90vh]">

            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-slate-800 px-6 py-4 shrink-0">
                <h3 class="font-semibold text-slate-100">{{ __('New Container') }}</h3>
                <div class="flex items-center gap-3">
                    {{-- CLI paste toggle --}}
                    <button wire:click="$set('cliPasteMode', {{ $cliPasteMode ? 'false' : 'true' }})"
                            class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg border transition {{ $cliPasteMode ? 'border-indigo-400 bg-indigo-500/10' : 'border-slate-700 text-slate-400 hover:border-slate-600' }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5l3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0021 18V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v12a2.25 2.25 0 002.25 2.25z"/></svg>
                        {{ __('CLI Paste') }}
                    </button>
                    <button wire:click="$set('showCreate', false)" class="text-slate-400 hover:text-slate-200">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <div class="overflow-y-auto flex-1 p-6 space-y-5">

                {{-- CLI Paste Mode --}}
                @if ($cliPasteMode)
                    <div class="space-y-3">
                        <p class="text-sm text-slate-300 font-medium">{{ __('Paste a :command command:', ['command' => '<code class="font-mono text-xs bg-slate-800 px-1 rounded">docker run</code>']) }}</p>
                        <textarea wire:model="cliCommand" rows="4"
                                  placeholder="docker run -d --name nginx -p 80:80 -e ENV=prod --restart unless-stopped nginx:alpine"
                                  class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 font-mono text-sm text-slate-100 focus:border-indigo-400 focus:outline-none resize-none"></textarea>
                        <button wire:click="parseCli"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                            {{ __('Parse & Fill Form') }}
                        </button>
                    </div>
                    <div class="border-t border-slate-800 pt-4">
                        <p class="text-xs text-slate-400">{{ __('Parsed fields will appear below for review before deploying.') }}</p>
                    </div>
                @endif

                {{-- Image --}}
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">{{ __('Image') }} <span class="text-rose-500">*</span></label>
                    <input wire:model="createForm.image" type="text" placeholder="nginx:alpine, mysql:8, myrepo/myapp:latest"
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    @error('createForm.image') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                {{-- Name + Hostname --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">{{ __('Container name') }}</label>
                        <input wire:model="createForm.name" type="text" placeholder="my-app"
                               class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">{{ __('Hostname') }}</label>
                        <input wire:model="createForm.hostname" type="text" placeholder="my-app-host"
                               class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    </div>
                </div>

                {{-- Ports --}}
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-sm font-semibold text-slate-300">{{ __('Port mappings') }}</label>
                        <button wire:click="addCreateField('ports')" type="button" class="text-xs text-indigo-400 hover:underline">+ Add</button>
                    </div>
                    <div class="space-y-2">
                        @foreach ($createForm['ports'] as $i => $port)
                            <div class="flex gap-2">
                                <input wire:model="createForm.ports.{{ $i }}" type="text" placeholder="8080:80"
                                       class="flex-1 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none font-mono">
                                @if (count($createForm['ports']) > 1)
                                    <button wire:click="removeCreateField('ports', {{ $i }})" class="text-slate-400 hover:text-rose-500 transition">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-slate-400">{{ __('Format:') }} <code class="font-mono">{{ $portBindingPlaceholder ?? 'host_port:container_port' }}</code></p>
                </div>

{{-- Env --}}
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-sm font-semibold text-slate-300">{{ __('Environment Variables') }}</label>
                        <button wire:click="addCreateField('env')" type="button" class="text-xs text-indigo-400 hover:underline">+ {{ __('Add') }}</button>
                    </div>
                </div>

                {{-- Volumes --}}
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-sm font-semibold text-slate-300">{{ __('Volume mounts') }}</label>
                        <button wire:click="addCreateField('volumes')" type="button" class="text-xs text-indigo-400 hover:underline">+ {{ __('Add') }}</button>
                    </div>
                </div>

                {{-- Network + Restart --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">{{ __('Network') }}</label>
                        <input wire:model="createForm.network" type="text" list="network-list" placeholder="bridge"
                               class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                        <datalist id="network-list">
                            <option value="bridge">
                            <option value="host">
                            <option value="none">
                            @foreach ($networks as $net)
                                <option value="{{ $net['Name'] ?? '' }}">
                            @endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">{{ __('Restart policy') }}</label>
                        <select wire:model="createForm.restart"
                                class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                            <option value="">no</option>
                            <option value="unless-stopped">unless-stopped</option>
                            <option value="always">always</option>
                            <option value="on-failure">on-failure</option>
                        </select>
                    </div>
                </div>

                {{-- Resource limits --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">{{ __('Memory limit') }}</label>
                        <input wire:model="createForm.memory" type="text" placeholder="512m, 1g …"
                               class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">{{ __('CPU limit') }}</label>
                        <input wire:model="createForm.cpus" type="text" placeholder="0.5, 2 …"
                               class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    </div>
                </div>

                {{-- Command --}}
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">{{ __('Command override') }} <span class="text-slate-400 font-normal">({{ __('optional') }})</span></label>
                    <input wire:model="createForm.command" type="text" placeholder="node server.js"
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none font-mono">
                </div>

            </div>

            {{-- Footer --}}
            <div class="flex justify-end gap-3 border-t border-slate-800 px-6 py-4 shrink-0">
                <button wire:click="$set('showCreate', false)" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-slate-100">{{ __('Cancel') }}</button>
                <button wire:click="createContainer" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-5 py-2 text-sm font-semibold text-white shadow-sm transition disabled:opacity-50">
                    <span wire:loading.delay wire:target="createContainer">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    </span>
                    {{ __('Deploy Container') }}
                </button>
            </div>
        </div>
    </div>
@endif
