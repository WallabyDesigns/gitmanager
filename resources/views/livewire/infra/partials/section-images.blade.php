<div class="flex items-center justify-between">
    <h2 class="text-base font-semibold text-slate-100">{{ __('Images') }}</h2>
    <button wire:click="$set('showPull', true)"
            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
        {{ __('Pull Image') }}
    </button>
</div>

@if (count($images) === 0)
    <div class="rounded-xl border border-dashed border-slate-700 p-12 text-center">
        <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0l4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0l-5.571 3-5.571-3"/></svg>
        <p class="mt-3 text-sm font-medium text-slate-400">{{ __('No images found locally.') }}</p>
        <button wire:click="$set('showPull', true)" class="mt-4 text-sm text-indigo-400 hover:underline">{{ __('Pull an image →') }}</button>
    </div>
@else
    <div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-800/50">
                    <tr>
                        @foreach ([__('Repository'), __('Tag'), __('Image ID'), __('Size'), __('Created'), __('Actions')] as $h)
                            <th class="px-4 py-3 text-xs font-semibold text-slate-400 uppercase tracking-wide">{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($images as $img)
                        @php $imgId = $img['ID'] ?? $img['Id'] ?? ''; @endphp
                        @php
                            $repo = trim((string) ($img['Repository'] ?? ''));
                            $tag = trim((string) ($img['Tag'] ?? ''));
                            $retagSource = $imgId;
                            if ($repo !== '' && $repo !== '<none>') {
                                $retagSource = $tag !== '' ? $repo.':'.$tag : $repo;
                            }
                        @endphp
                        <tr class="hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium text-slate-100 font-mono text-xs">{{ $img['Repository'] ?? '&lt;none&gt;' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-md bg-slate-800 px-2 py-0.5 text-xs font-medium text-slate-300">{{ $img['Tag'] ?? 'latest' }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ Str::limit($imgId, 14) }}</td>
                            <td class="px-4 py-3 text-slate-400">{{ $img['Size'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-400 text-xs">{{ $img['CreatedSince'] ?? $img['Created'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button wire:click="inspectImage('{{ $imgId }}')" class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-400 hover:border-indigo-300 hover:text-indigo-600 transition">{{ __('Inspect') }}</button>
                                    <button wire:click="openRetagImageModal('{{ addslashes($retagSource) }}')" class="text-xs px-2 py-1 rounded border border-indigo-500/60 text-indigo-300 hover:text-indigo-100 transition">{{ __('Retag') }}</button>
                                    <button wire:click="removeImage('{{ $imgId }}')"
                                            wire:confirm="{{ __('Remove image :image?', ['image' => $img['Repository'] ?? $imgId . ':' . ($img['Tag'] ?? '')]) }}"
                                            class="text-xs px-2 py-1 rounded border border-slate-700 text-slate-500 hover:border-rose-300 hover:text-rose-600 transition">{{ __('Remove') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- Retag Image Modal --}}
@if ($showRetagImage ?? false)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showRetagImage', false)">
        <div class="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 px-6 py-4">
                <h3 class="font-semibold text-slate-100">{{ __('Retag Image') }}</h3>
                <button wire:click="$set('showRetagImage', false)" class="text-slate-400 hover:text-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('Source image') }}</label>
                    <input wire:model.defer="retagSourceImage" type="text"
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    @error('retagSourceImage') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('New tag') }}</label>
                    <input wire:model.defer="retagTargetImage" type="text" placeholder="my-registry/app:stable"
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    @error('retagTargetImage') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showRetagImage', false)" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-slate-100">{{ __('Cancel') }}</button>
                    <button wire:click="retagImage" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition">{{ __('Save Tag') }}</button>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Pull Image Modal --}}
@if ($showPull)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="$set('showPull', false)">
        <div class="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 px-6 py-4">
                <h3 class="font-semibold text-slate-100">{{ __('Pull Image') }}</h3>
                <button wire:click="$set('showPull', false)" class="text-slate-400 hover:text-slate-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">{{ __('Image name') }}</label>
                    <input wire:model="pullInput" type="text" placeholder="nginx:alpine, mysql:8, ubuntu:22.04 …"
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-100 focus:border-indigo-400 focus:outline-none">
                    <p class="mt-1.5 text-xs text-slate-500">{{ __('Include a tag (e.g. :tag) or leave without for :latest.', ['tag' => '<code class="font-mono">redis:7-alpine</code>', 'latest' => '<code class="font-mono">:latest</code>']) }}</p>
                </div>
                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showPull', false)" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-slate-100">{{ __('Cancel') }}</button>
                    <button wire:click="pullImage" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition disabled:opacity-50">
                        <span wire:loading.delay wire:target="pullImage">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        </span>
                        {{ __('Pull') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
