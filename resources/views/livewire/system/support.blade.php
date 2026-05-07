<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs', ['systemTab' => 'support'])

            <div class="space-y-6">
                <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-6 space-y-4">
                    <div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-100">{{ __('Enterprise Support') }}</h3>
                            <p class="text-sm text-slate-400">{{ __('Having a problem with Git Web Manager? Let us help!') }}</p>
                        </div>
                    </div>

                    @if (! $isEnterprise)
                        <div class="rounded-lg border border-amber-400/50 bg-amber-500/10 p-4 text-sm text-amber-300">
                            {{ __('Enterprise Support is available on the Enterprise edition.') }}
                            <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Support' } }));" class="ml-2 inline-flex items-center rounded-md border border-amber-400/60 px-2 py-1 text-xs font-semibold hover:bg-amber-500/20">
                                {{ __('Upgrade') }}
                            </button>
                        </div>
                    @elseif (! $licenseConfigured)
                        <div class="rounded-lg border border-amber-400/50 bg-amber-500/10 p-4 text-sm text-amber-300">
                            {{ __('Configure and verify your enterprise license in System → Edition & License before opening support tickets.') }}
                        </div>
                    @elseif (! $supportEnabled)
                        <div class="rounded-lg border border-rose-400/50 bg-rose-500/10 p-4 text-sm text-rose-300">
                            {{ __('Support integration is disabled on this installation (:env).', ['env' => 'GWM_SUPPORT_ENABLED=false']) }}
                        </div>
                    @elseif ($supportEndpoint === '')
                        <div class="rounded-lg border border-rose-400/50 bg-rose-500/10 p-4 text-sm text-rose-300">
                            {{ __('Support endpoint not configured. Set :env1 or configure :env2 so it can be derived automatically.', ['env1' => 'GWM_SUPPORT_API_URL', 'env2' => 'GWM_LICENSE_VERIFY_URL']) }}
                        </div>
                    @endif
                </div>

                @if ($isEnterprise && $licenseConfigured && $supportEnabled && $supportEndpoint !== '')
                    @php
                        $hasTickets = count($tickets) > 0;
                        $hasOpenTicketTabs = count($openTicketTabs) > 0;
                        $showTicketToolbar = $hasTickets || $hasOpenTicketTabs;
                    @endphp
                    <div class="bg-slate-900 shadow-sm sm:rounded-xl border border-slate-800 p-4 sm:p-6 space-y-6">
                        @if ($showTicketToolbar)
                            <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 xl:flex-row xl:items-center xl:justify-between">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        wire:click="showTicketsPane"
                                        class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition {{ $activePane === 'tickets' ? 'border-indigo-400/50 bg-indigo-500/10' : 'border-slate-800 text-slate-300 hover:border-slate-700 hover:text-white' }}"
                                    >
                                        <span>{{ __('All Tickets') }}</span>
                                        <span class="inline-flex items-center justify-center rounded-full px-2 py-0.5 text-[11px] bg-slate-800 text-slate-300">{{ count($tickets) }}</span>
                                    </button>

                                    @foreach ($openTicketTabs as $tab)
                                        @php
                                            $ticketId = (int) ($tab['id'] ?? 0);
                                            $isActiveTab = $activePane === 'ticket' && $selectedTicketId === $ticketId;
                                        @endphp
                                        <div
                                            wire:key="support-tab-{{ $ticketId }}"
                                            class="inline-flex items-stretch rounded-lg border {{ $isActiveTab ? 'border-indigo-400/50' : 'border-slate-800 bg-slate-950/40' }}"
                                        >
                                            <button
                                                type="button"
                                                wire:click="openTicket({{ $ticketId }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openTicket({{ $ticketId }})"
                                                class="inline-flex items-center gap-2 px-3 py-2 text-left disabled:opacity-70 disabled:cursor-not-allowed"
                                            >
                                                <x-loading-spinner target="openTicket({{ $ticketId }})" size="w-3 h-3" />
                                                <span class="flex flex-col items-start leading-tight">
                                                    <span class="text-[10px] uppercase tracking-wide text-slate-500">{{ $tab['ticket_number'] ?? ('SUP-'.$ticketId) }}</span>
                                                    <span class="text-sm font-medium {{ $isActiveTab ? 'text-indigo-300' : 'text-slate-100' }}">{{ $tab['subject'] ?? 'Support Ticket' }}</span>
                                                </span>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click.stop="closeTicketTab({{ $ticketId }})"
                                                class="border-l px-2 text-slate-400 transition border-slate-800 hover:text-slate-200"
                                                aria-label="Close ticket tab"
                                            >
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        wire:click="startTicketComposer"
                                        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        New Ticket
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="refreshTickets"
                                        wire:loading.attr="disabled"
                                        wire:target="refreshTickets"
                                        class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm disabled:opacity-70 disabled:cursor-not-allowed border-slate-700 text-slate-200 hover:text-white"
                                    >
                                        <x-loading-spinner target="refreshTickets" size="w-3 h-3" />
                                        Refresh
                                    </button>
                                </div>
                            </div>
                        @endif

                        @if ($activePane === 'tickets')
                            <div class="space-y-6">
                                @if ($hasTickets)
                                    <div>
                                        <div class="text-xs uppercase tracking-[0.2em] text-slate-500">{{ __('All Tickets') }}</div>
                                        <h4 class="mt-1 text-xl font-semibold text-slate-100">{{ __('Support Inbox') }}</h4>
                                        <p class="text-sm text-slate-400">{{ __('Open a new ticket or jump back into any existing thread from this installation.') }}</p>
                                    </div>
                                @endif

                                @if ($showComposer)
                                    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr),280px]">
                                        <div class="space-y-4">
                                            <div>
                                                <h5 class="text-base font-semibold text-slate-100">{{ __('Open New Ticket') }}</h5>
                                                <p class="text-sm text-slate-400">{{ __('Include concrete details, recent logs, and the last known good behavior.') }}</p>
                                            </div>

                                            <div>
                                                <label class="text-xs uppercase tracking-wide text-slate-500">{{ __('Subject') }}</label>
                                                <input type="text" wire:model.defer="subject" maxlength="180" class="mt-2 w-full rounded-md border p-2 text-sm border-slate-700 bg-slate-950 text-slate-100" placeholder="Example: Deployment timeout after npm install" />
                                                <x-input-error :messages="$errors->get('subject')" class="mt-2" />
                                            </div>

                                            <div>
                                                <label class="text-xs uppercase tracking-wide text-slate-500">{{ __('Priority') }}</label>
                                                <select wire:model.defer="priority" class="mt-2 w-full rounded-md border p-2 text-sm border-slate-700 bg-slate-950 text-slate-100">
                                                    <option value="normal">{{ __('Normal') }}</option>
                                                    <option value="high">{{ __('High') }}</option>
                                                    <option value="urgent">{{ __('Urgent') }}</option>
                                                </select>
                                                <x-input-error :messages="$errors->get('priority')" class="mt-2" />
                                            </div>

                                            <div>
                                                <label class="text-xs uppercase tracking-wide text-slate-500">{{ __('Details') }}</label>
                                                <div class="mt-2">
                                                    <x-rich-text-editor
                                                        wire:model.defer="message"
                                                        :value="$message"
                                                        placeholder="Describe the problem, steps to reproduce, recent changes, and what you expected to happen."
                                                        min-height="14rem"
                                                    />
                                                </div>
                                                <x-input-error :messages="$errors->get('message')" class="mt-2" />
                                            </div>

                                            <div class="flex flex-wrap gap-3">
                                                <button
                                                    type="button"
                                                    wire:click="submitTicket"
                                                    wire:loading.attr="disabled"
                                                    wire:target="submitTicket"
                                                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-70 disabled:cursor-not-allowed"
                                                >
                                                    <x-loading-spinner target="submitTicket" size="w-3 h-3" />
                                                    {{ __('Submit Ticket') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="cancelTicketComposer"
                                                    class="inline-flex items-center rounded-md border px-4 py-2 text-sm border-slate-700 text-slate-300 hover:text-white"
                                                >
                                                    {{ __('Cancel') }}
                                                </button>
                                            </div>
                                        </div>

                                        <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-5 space-y-3">
                                            <div class="text-xs uppercase tracking-[0.2em] text-slate-500">{{ __('Helpful Context') }}</div>
                                            <h5 class="text-base font-semibold text-slate-100">{{ __('What to include') }}</h5>
                                            <ul class="space-y-2 text-sm text-slate-400">
                                                <li>{{ __('The command or workflow that failed.') }}</li>
                                                <li>{{ __('The exact error message or log excerpt.') }}</li>
                                                <li>{{ __('What changed right before the issue started.') }}</li>
                                                <li>{{ __('Whether the problem affects one project or the whole install.') }}</li>
                                            </ul>
                                        </div>
                                    </div>
                                @endif

                                @if ($hasTickets)
                                    <div class="space-y-3">
                                        @foreach ($tickets as $ticket)
                                            @php
                                                $ticketId = (int) ($ticket['id'] ?? 0);
                                                $ticketStatus = ucfirst((string) ($ticket['status'] ?? 'open'));
                                                $ticketPriority = ucfirst((string) ($ticket['priority'] ?? 'normal'));
                                                $ticketNumber = (string) ($ticket['ticket_number'] ?? ('SUP-'.$ticketId));
                                                $isSelected = $selectedTicketId === $ticketId;
                                            @endphp
                                            <button
                                                wire:key="support-ticket-row-{{ $ticketId }}"
                                                type="button"
                                                wire:click="openTicket({{ $ticketId }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openTicket({{ $ticketId }})"
                                                class="w-full rounded-xl border px-4 py-4 text-left transition disabled:opacity-70 disabled:cursor-not-allowed {{ $isSelected ? 'border-indigo-400/60' : 'border-slate-800 hover:border-slate-700 bg-slate-950/20' }}"
                                            >
                                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                                    <div class="space-y-2">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                                                <x-loading-spinner target="openTicket({{ $ticketId }})" size="w-3 h-3" />
                                                                {{ $ticketNumber }}
                                                            </span>
                                                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] uppercase tracking-wide border-slate-700 text-slate-300">{{ $ticketStatus }}</span>
                                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] uppercase tracking-wide bg-slate-800 text-slate-300">{{ $ticketPriority }}</span>
                                                        </div>

                                                        <div class="text-base font-semibold text-slate-100">{{ (string) ($ticket['subject'] ?? __('Untitled ticket')) }}</div>
                                                    </div>

                                                    <div class="grid gap-1 text-xs text-slate-400 sm:text-right">
                                                        <span>{{ __('Created') }}: {{ \App\Support\DateFormatter::forUser($ticket['created_at'] ?? null, 'M j, Y g:i a', __('Unknown')) }}</span>
                                                        <span>{{ __('Last update:') }} {{ \App\Support\DateFormatter::forUser($ticket['last_message_at'] ?? null, 'M j, Y g:i a', __('Unknown')) }}</span>
                                                    </div>
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif (! $showComposer)
                                    <div class="rounded-xl border border-dashed border-slate-700 p-8 text-center">
                                        <div class="text-base font-semibold text-slate-100">{{ __('No support tickets yet') }}</div>
                                        <p class="mt-2 text-sm text-slate-400">{{ __('Having a problem you can\'t fix? Let us help!') }}</p>
                                        <button
                                            type="button"
                                            wire:click="startTicketComposer"
                                            class="mt-4 inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                        >
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                            {{ __('Create First Ticket') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @elseif (is_array($selectedTicket))
                            <div class="space-y-6">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <button
                                            type="button"
                                            wire:click="showTicketsPane"
                                            class="inline-flex items-center gap-2 text-sm transition text-slate-400 hover:text-white"
                                        >
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 19.5-7.5-7.5 7.5-7.5" />
                                            </svg>
                                            {{ __('Back to all tickets') }}
                                        </button>
                                        <div class="mt-3 text-xs uppercase tracking-[0.2em] text-slate-500">{{ $selectedTicket['ticket_number'] ?? __('Ticket') }}</div>
                                        <h4 class="mt-1 text-2xl font-semibold text-slate-100">{{ $selectedTicket['subject'] ?? __('Support Ticket') }}</h4>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs uppercase tracking-wide border-slate-700 text-slate-300">
                                            {{ strtoupper((string) ($selectedTicket['status'] ?? 'open')) }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs uppercase tracking-wide bg-slate-800 text-slate-300">
                                            {{ strtoupper((string) ($selectedTicket['priority'] ?? 'normal')) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 text-sm">
                                    <div class="rounded-lg border border-slate-800 bg-slate-950/30 p-4">
                                        <div class="text-xs uppercase tracking-wide text-slate-500">{{ __('Created') }}</div>
                                        <div class="mt-2 text-slate-100">{{ \App\Support\DateFormatter::forUser($selectedTicket['created_at'] ?? null, 'M j, Y g:i a', __('Unknown')) }}</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-800 bg-slate-950/30 p-4">
                                        <div class="text-xs uppercase tracking-wide text-slate-500">{{ __('Last Customer Message') }}</div>
                                        <div class="mt-2 text-slate-100">{{ \App\Support\DateFormatter::forUser($selectedTicket['last_customer_message_at'] ?? null, 'M j, Y g:i a', __('Unknown')) }}</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-800 bg-slate-950/30 p-4">
                                        <div class="text-xs uppercase tracking-wide text-slate-500">{{ __('Last Admin Reply') }}</div>
                                        <div class="mt-2 text-slate-100">{{ \App\Support\DateFormatter::forUser($selectedTicket['last_admin_reply_at'] ?? null, 'M j, Y g:i a', __('Not yet')) }}</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-800 bg-slate-950/30 p-4">
                                        <div class="text-xs uppercase tracking-wide text-slate-500">{{ __('Replies') }}</div>
                                        <div class="mt-2 text-slate-100">{{ count($selectedTicket['messages'] ?? []) }}</div>
                                    </div>
                                </div>

                                <div class="space-y-3 max-h-[520px] overflow-y-auto pr-1">
                                    @forelse (($selectedTicket['messages'] ?? []) as $entry)
                                        @php
                                            $senderType = strtolower((string) ($entry['sender_type'] ?? 'customer'));
                                            $isAdminMessage = $senderType === 'admin';
                                            $messageView = \App\Support\SupportMessageView::fromEntry($entry, auth()->user()?->locale ?? app()->getLocale());
                                        @endphp
                                        <article
                                            x-data="{ original: false }"
                                            class="rounded-xl border p-4 {{ $isAdminMessage ? 'border-indigo-400/50 bg-indigo-500/10' : 'border-slate-800 bg-slate-950/40' }}"
                                        >
                                            <div class="flex items-center justify-between gap-2 mb-2">
                                                <div class="text-xs font-semibold {{ $isAdminMessage ? 'text-indigo-300' : 'text-slate-200' }}">
                                                    {{ $isAdminMessage ? 'Admin Reply' : 'You' }}
                                                    @if (! empty($entry['sender_label']))
                                                        <span class="font-normal text-slate-400">· {{ $entry['sender_label'] }}</span>
                                                    @endif
                                                </div>
                                                <div class="text-[11px] text-slate-400">
                                                    {{ \App\Support\DateFormatter::forUser($entry['created_at'] ?? null, 'M j, Y g:i a', 'Unknown') }}
                                                </div>
                                            </div>
                                            @if ($messageView['has_translation'])
                                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-xs border-slate-700 bg-slate-950/40 text-slate-400">
                                                    <span x-show="! original">{{ __('Translated from :source', ['source' => $messageView['source_label']]) }}</span>
                                                    <span x-show="original" x-cloak>{{ __('Showing original :source', ['source' => $messageView['source_label']]) }}</span>
                                                    <button type="button" class="font-medium hover:text-indigo-500 text-indigo-300" @click="original = ! original">
                                                        <span x-show="! original">{{ __('Show original') }}</span>
                                                        <span x-show="original" x-cloak>{{ __('Show translation') }}</span>
                                                    </button>
                                                </div>
                                                <div x-show="! original" class="gwm-support-richtext text-sm text-slate-200">
                                                    {!! $messageView['translated_html'] !!}
                                                </div>
                                                <div x-show="original" x-cloak class="gwm-support-richtext text-sm text-slate-200">
                                                    {!! $messageView['original_html'] !!}
                                                </div>
                                                <div class="mt-3 text-[11px] text-slate-500">
                                                    Translated from {{ $messageView['source_label'] }} to {{ $messageView['target_label'] }}.
                                                </div>
                                            @else
                                                <div class="gwm-support-richtext text-sm text-slate-200">
                                                    {!! $messageView['original_html'] !!}
                                                </div>
                                            @endif
                                        </article>
                                    @empty
                                        <div class="rounded-lg border border-slate-800 p-4 text-sm text-slate-400">
                                            No messages yet.
                                        </div>
                                    @endforelse
                                </div>

                                <div class="">
                                    <label class="text-xs uppercase tracking-wide text-slate-500">{{ __('Send Follow-Up') }}</label>
                                    <div class="mt-2">
                                        <x-rich-text-editor
                                            wire:model.defer="replyMessage"
                                            :value="$replyMessage"
                                            placeholder="Add more details or respond to admin follow-up questions."
                                            min-height="12rem"
                                        />
                                    </div>
                                    <x-input-error :messages="$errors->get('replyMessage')" class="mt-2" />
                                    <div class="mt-4">
                                        <button
                                            type="button"
                                            wire:click="sendReply"
                                            wire:loading.attr="disabled"
                                            wire:target="sendReply"
                                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-70 disabled:cursor-not-allowed"
                                        >
                                            <x-loading-spinner target="sendReply" size="w-3 h-3" />
                                            Send Message
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="rounded-xl border border-dashed border-slate-700 p-10 text-center">
                                <div class="text-base font-semibold text-slate-100">{{ __('Select a ticket tab to continue') }}</div>
                                <p class="mt-2 text-sm text-slate-400">{{ __('Open any ticket from the inbox to read the thread and send follow-up replies.') }}</p>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
