<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs')

            <div class="space-y-6">
                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                    <div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Enterprise Support</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Having a problem with Git Web Manager? Let us help!</p>
                        </div>
                    </div>

                    @if (! $isEnterprise)
                        <div class="rounded-lg border border-amber-400/50 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300">
                            Enterprise Support is available on the Enterprise edition.
                            <button type="button" onclick="window.dispatchEvent(new CustomEvent('gwm-open-enterprise-modal', { detail: { feature: 'Enterprise Support' } }));" class="ml-2 inline-flex items-center rounded-md border border-amber-400/60 px-2 py-1 text-xs font-semibold hover:bg-amber-500/20">
                                Upgrade
                            </button>
                        </div>
                    @elseif (! $licenseConfigured)
                        <div class="rounded-lg border border-amber-400/50 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300">
                            Configure and verify your enterprise license in <strong>System → Edition &amp; License</strong> before opening support tickets.
                        </div>
                    @elseif (! $supportEnabled)
                        <div class="rounded-lg border border-rose-400/50 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-300">
                            Support integration is disabled on this installation (`GWM_SUPPORT_ENABLED=false`).
                        </div>
                    @elseif ($supportEndpoint === '')
                        <div class="rounded-lg border border-rose-400/50 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-300">
                            Support endpoint not configured. Set <code>GWM_SUPPORT_API_URL</code> or configure <code>GWM_LICENSE_VERIFY_URL</code> so it can be derived automatically.
                        </div>
                    @elseif ($testingBypass)
                        <div class="rounded-lg border border-amber-400/50 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                            Enterprise testing override is active. Support calls are running in local testing bypass mode.
                        </div>
                    @endif
                </div>

                @if ($isEnterprise && $licenseConfigured && $supportEnabled && $supportEndpoint !== '')
                    @php
                        $hasTickets = count($tickets) > 0;
                        $hasOpenTicketTabs = count($openTicketTabs) > 0;
                        $showTicketToolbar = $hasTickets || $hasOpenTicketTabs;
                    @endphp
                    <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-4 sm:p-6 space-y-6">
                        @if ($showTicketToolbar)
                            <div class="flex flex-col gap-4 border-b border-slate-200/70 dark:border-slate-800 pb-4 xl:flex-row xl:items-center xl:justify-between">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        wire:click="showTicketsPane"
                                        class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition {{ $activePane === 'tickets' ? 'border-indigo-400/50 bg-indigo-500/10 text-indigo-700 dark:text-indigo-200' : 'border-slate-200/70 text-slate-600 hover:border-slate-300 hover:text-slate-900 dark:border-slate-800 dark:text-slate-300 dark:hover:border-slate-700 dark:hover:text-white' }}"
                                    >
                                        <span>All Tickets</span>
                                        <span class="inline-flex items-center justify-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ count($tickets) }}</span>
                                    </button>

                                    @foreach ($openTicketTabs as $tab)
                                        @php
                                            $ticketId = (int) ($tab['id'] ?? 0);
                                            $isActiveTab = $activePane === 'ticket' && $selectedTicketId === $ticketId;
                                        @endphp
                                        <div
                                            wire:key="support-tab-{{ $ticketId }}"
                                            class="inline-flex items-stretch rounded-lg border {{ $isActiveTab ? 'border-indigo-400/50 bg-indigo-500/10' : 'border-slate-200/70 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-950/40' }}"
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
                                                    <span class="text-[10px] uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $tab['ticket_number'] ?? ('SUP-'.$ticketId) }}</span>
                                                    <span class="text-sm font-medium {{ $isActiveTab ? 'text-indigo-700 dark:text-indigo-100' : 'text-slate-800 dark:text-slate-100' }}">{{ $tab['subject'] ?? 'Support Ticket' }}</span>
                                                </span>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click.stop="closeTicketTab({{ $ticketId }})"
                                                class="border-l border-slate-200/70 px-2 text-slate-400 transition hover:text-slate-700 dark:border-slate-800 dark:hover:text-slate-200"
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
                                        class="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:text-slate-900 disabled:opacity-70 disabled:cursor-not-allowed dark:border-slate-700 dark:text-slate-200 dark:hover:text-white"
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
                                        <div class="text-xs uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">All Tickets</div>
                                        <h4 class="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-100">Support Inbox</h4>
                                        <p class="text-sm text-slate-500 dark:text-slate-400">Open a new ticket or jump back into any existing thread from this installation.</p>
                                    </div>
                                @endif

                                @if ($showComposer)
                                    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr),280px]">
                                        <div class="space-y-4">
                                            <div>
                                                <h5 class="text-base font-semibold text-slate-900 dark:text-slate-100">Open New Ticket</h5>
                                                <p class="text-sm text-slate-500 dark:text-slate-400">Include concrete details, recent logs, and the last known good behavior.</p>
                                            </div>

                                            <div>
                                                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Subject</label>
                                                <input type="text" wire:model.defer="subject" maxlength="180" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/80 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Example: Deployment timeout after npm install" />
                                                <x-input-error :messages="$errors->get('subject')" class="mt-2" />
                                            </div>

                                            <div>
                                                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Priority</label>
                                                <select wire:model.defer="priority" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/80 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                                    <option value="normal">Normal</option>
                                                    <option value="high">High</option>
                                                    <option value="urgent">Urgent</option>
                                                </select>
                                                <x-input-error :messages="$errors->get('priority')" class="mt-2" />
                                            </div>

                                            <div>
                                                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Details</label>
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
                                                    Submit Ticket
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="cancelTicketComposer"
                                                    class="inline-flex items-center rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:text-white"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>

                                        <div class="rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white/70 dark:bg-slate-950/30 p-5 space-y-3">
                                            <div class="text-xs uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">Helpful Context</div>
                                            <h5 class="text-base font-semibold text-slate-900 dark:text-slate-100">What to include</h5>
                                            <ul class="space-y-2 text-sm text-slate-500 dark:text-slate-400">
                                                <li>The command or workflow that failed.</li>
                                                <li>The exact error message or log excerpt.</li>
                                                <li>What changed right before the issue started.</li>
                                                <li>Whether the problem affects one project or the whole install.</li>
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
                                                class="w-full rounded-xl border px-4 py-4 text-left transition disabled:opacity-70 disabled:cursor-not-allowed {{ $isSelected ? 'border-indigo-400/60 bg-indigo-500/10' : 'border-slate-200/70 hover:border-slate-300 dark:border-slate-800 dark:hover:border-slate-700 bg-white/60 dark:bg-slate-950/20' }}"
                                            >
                                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                                    <div class="space-y-2">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                                <x-loading-spinner target="openTicket({{ $ticketId }})" size="w-3 h-3" />
                                                                {{ $ticketNumber }}
                                                            </span>
                                                            <span class="inline-flex items-center rounded-full border border-slate-300/70 px-2 py-0.5 text-[10px] uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:text-slate-300">{{ $ticketStatus }}</span>
                                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300">{{ $ticketPriority }}</span>
                                                        </div>

                                                        <div class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ (string) ($ticket['subject'] ?? 'Untitled ticket') }}</div>
                                                    </div>

                                                    <div class="grid gap-1 text-xs text-slate-500 dark:text-slate-400 sm:text-right">
                                                        <span>Created: {{ \App\Support\DateFormatter::forUser($ticket['created_at'] ?? null, 'M j, Y g:i a', 'Unknown') }}</span>
                                                        <span>Last update: {{ \App\Support\DateFormatter::forUser($ticket['last_message_at'] ?? null, 'M j, Y g:i a', 'Unknown') }}</span>
                                                    </div>
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif (! $showComposer)
                                    <div class="rounded-xl border border-dashed border-slate-300/80 dark:border-slate-700 p-8 text-center">
                                        <div class="text-base font-semibold text-slate-900 dark:text-slate-100">No support tickets yet</div>
                                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Having a problem you can't fix? Let us help!</p>
                                        <button
                                            type="button"
                                            wire:click="startTicketComposer"
                                            class="mt-4 inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                        >
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                            Create First Ticket
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
                                            class="inline-flex items-center gap-2 text-sm text-slate-500 transition hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
                                        >
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 19.5-7.5-7.5 7.5-7.5" />
                                            </svg>
                                            Back to all tickets
                                        </button>
                                        <div class="mt-3 text-xs uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">{{ $selectedTicket['ticket_number'] ?? 'Ticket' }}</div>
                                        <h4 class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ $selectedTicket['subject'] ?? 'Support Ticket' }}</h4>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        <span class="inline-flex items-center rounded-full border border-slate-300/70 px-3 py-1 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:text-slate-300">
                                            {{ strtoupper((string) ($selectedTicket['status'] ?? 'open')) }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                                            {{ strtoupper((string) ($selectedTicket['priority'] ?? 'normal')) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 text-sm">
                                    <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/30 p-4">
                                        <div class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Created</div>
                                        <div class="mt-2 text-slate-900 dark:text-slate-100">{{ \App\Support\DateFormatter::forUser($selectedTicket['created_at'] ?? null, 'M j, Y g:i a', 'Unknown') }}</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/30 p-4">
                                        <div class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Last Customer Message</div>
                                        <div class="mt-2 text-slate-900 dark:text-slate-100">{{ \App\Support\DateFormatter::forUser($selectedTicket['last_customer_message_at'] ?? null, 'M j, Y g:i a', 'Unknown') }}</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/30 p-4">
                                        <div class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Last Admin Reply</div>
                                        <div class="mt-2 text-slate-900 dark:text-slate-100">{{ \App\Support\DateFormatter::forUser($selectedTicket['last_admin_reply_at'] ?? null, 'M j, Y g:i a', 'Not yet') }}</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/30 p-4">
                                        <div class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Replies</div>
                                        <div class="mt-2 text-slate-900 dark:text-slate-100">{{ count($selectedTicket['messages'] ?? []) }}</div>
                                    </div>
                                </div>

                                <div class="space-y-3 max-h-[520px] overflow-y-auto pr-1">
                                    @forelse (($selectedTicket['messages'] ?? []) as $entry)
                                        @php
                                            $senderType = strtolower((string) ($entry['sender_type'] ?? 'customer'));
                                            $isAdminMessage = $senderType === 'admin';
                                        @endphp
                                        <article class="rounded-xl border p-4 {{ $isAdminMessage ? 'border-indigo-300/50 bg-indigo-500/10' : 'border-slate-200/70 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/40' }}">
                                            <div class="flex items-center justify-between gap-2 mb-2">
                                                <div class="text-xs font-semibold {{ $isAdminMessage ? 'text-indigo-700 dark:text-indigo-300' : 'text-slate-700 dark:text-slate-200' }}">
                                                    {{ $isAdminMessage ? 'Admin Reply' : 'You' }}
                                                    @if (! empty($entry['sender_label']))
                                                        <span class="font-normal text-slate-500 dark:text-slate-400">· {{ $entry['sender_label'] }}</span>
                                                    @endif
                                                </div>
                                                <div class="text-[11px] text-slate-500 dark:text-slate-400">
                                                    {{ \App\Support\DateFormatter::forUser($entry['created_at'] ?? null, 'M j, Y g:i a', 'Unknown') }}
                                                </div>
                                            </div>
                                            <div class="gwm-support-richtext text-sm text-slate-800 dark:text-slate-200">
                                                {!! (string) ($entry['message_html'] ?? nl2br(e((string) ($entry['message'] ?? '')))) !!}
                                            </div>
                                        </article>
                                    @empty
                                        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4 text-sm text-slate-500 dark:text-slate-400">
                                            No messages yet.
                                        </div>
                                    @endforelse
                                </div>

                                <div class="">
                                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Send Follow-Up</label>
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
                            <div class="rounded-xl border border-dashed border-slate-300/80 dark:border-slate-700 p-10 text-center">
                                <div class="text-base font-semibold text-slate-900 dark:text-slate-100">Select a ticket tab to continue</div>
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Open any ticket from the inbox to read the thread and send follow-up replies.</p>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
