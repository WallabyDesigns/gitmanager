<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-[260px,1fr]">
            @include('livewire.system.partials.tabs')

            <div class="space-y-6">
                <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Enterprise Support</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Open tickets from this installation and receive admin responses from the licensing portal.</p>
                        </div>
                        @if ($isEnterprise && $licenseConfigured && $supportEnabled)
                            <button type="button" wire:click="refreshTickets" class="px-3 py-2 rounded-md border border-slate-200 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                                Refresh
                            </button>
                        @endif
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
                            Configure and verify your enterprise license in <strong>System → Edition & License</strong> before opening support tickets.
                        </div>
                    @elseif (! $supportEnabled)
                        <div class="rounded-lg border border-rose-400/50 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-300">
                            Support integration is disabled on this installation (`GWM_SUPPORT_ENABLED=false`).
                        </div>
                    @elseif ($supportEndpoint === '')
                        <div class="rounded-lg border border-rose-400/50 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-300">
                            Support endpoint not configured. Set <code>GWM_SUPPORT_API_URL</code> or configure <code>GWM_LICENSE_VERIFY_URL</code> so it can be derived automatically.
                        </div>
                    @else
                        <div class="text-xs text-slate-500 dark:text-slate-400">
                            Support API endpoint:
                            <code>{{ $supportEndpoint }}</code>
                        </div>
                        @if ($testingBypass)
                            <div class="rounded-lg border border-amber-400/50 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                                Enterprise testing override is active. Support calls are running in local testing bypass mode.
                            </div>
                        @endif
                    @endif
                </div>

                @if ($isEnterprise && $licenseConfigured && $supportEnabled && $supportEndpoint !== '')
                    <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
                        <div class="space-y-6">
                            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                                <div>
                                    <h4 class="text-base font-semibold text-slate-900 dark:text-slate-100">Open New Ticket</h4>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">Include concrete details, recent logs, and expected behavior.</p>
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Subject</label>
                                    <input type="text" wire:model.defer="subject" maxlength="180" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Example: Deployment timeout after npm install" />
                                    <x-input-error :messages="$errors->get('subject')" class="mt-2" />
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Priority</label>
                                    <select wire:model.defer="priority" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                        <option value="normal">Normal</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                    <x-input-error :messages="$errors->get('priority')" class="mt-2" />
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Details</label>
                                    <textarea wire:model.defer="message" rows="7" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Describe the problem, steps to reproduce, and recent changes."></textarea>
                                    <x-input-error :messages="$errors->get('message')" class="mt-2" />
                                </div>

                                <button type="button" wire:click="submitTicket" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500">
                                    Submit Ticket
                                </button>
                            </div>

                            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-base font-semibold text-slate-900 dark:text-slate-100">My Tickets</h4>
                                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ count($tickets) }} total</span>
                                </div>

                                <div class="space-y-2 max-h-[520px] overflow-y-auto pr-1">
                                    @forelse ($tickets as $ticket)
                                        @php
                                            $ticketId = (int) ($ticket['id'] ?? 0);
                                            $isActive = $selectedTicketId === $ticketId;
                                            $ticketStatus = ucfirst((string) ($ticket['status'] ?? 'open'));
                                            $ticketNumber = (string) ($ticket['ticket_number'] ?? ('SUP-'.$ticketId));
                                        @endphp
                                        <button
                                            type="button"
                                            wire:click="openTicket({{ $ticketId }})"
                                            class="w-full text-left rounded-lg border px-3 py-2 transition {{ $isActive ? 'border-indigo-400/60 bg-indigo-500/10' : 'border-slate-200/70 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700' }}"
                                        >
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $ticketNumber }}</span>
                                                <span class="text-[10px] uppercase tracking-wide px-2 py-0.5 rounded-full border border-slate-300/60 dark:border-slate-700 text-slate-500 dark:text-slate-300">{{ $ticketStatus }}</span>
                                            </div>
                                            <div class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100 line-clamp-2">{{ (string) ($ticket['subject'] ?? 'Untitled ticket') }}</div>
                                            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                                Last update: {{ \App\Support\DateFormatter::forUser($ticket['last_message_at'] ?? null, 'M j, Y g:i a', 'Unknown') }}
                                            </div>
                                        </button>
                                    @empty
                                        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4 text-sm text-slate-500 dark:text-slate-400">
                                            No support tickets yet.
                                        </div>
                                    @endforelse
                                </div>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                            @if (is_array($selectedTicket))
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $selectedTicket['ticket_number'] ?? 'Ticket' }}</div>
                                        <h4 class="text-lg font-semibold text-slate-900 dark:text-slate-100 mt-1">{{ $selectedTicket['subject'] ?? 'Support Ticket' }}</h4>
                                    </div>
                                    <div class="text-xs uppercase tracking-wide px-2 py-1 rounded-full border border-slate-300/70 dark:border-slate-700 text-slate-500 dark:text-slate-300">
                                        {{ strtoupper((string) ($selectedTicket['status'] ?? 'open')) }}
                                    </div>
                                </div>

                                <div class="grid gap-2 sm:grid-cols-2 text-xs text-slate-500 dark:text-slate-400">
                                    <div>Priority: <span class="text-slate-700 dark:text-slate-200">{{ ucfirst((string) ($selectedTicket['priority'] ?? 'normal')) }}</span></div>
                                    <div>Created: <span class="text-slate-700 dark:text-slate-200">{{ \App\Support\DateFormatter::forUser($selectedTicket['created_at'] ?? null, 'M j, Y g:i a', 'Unknown') }}</span></div>
                                    <div>Last customer message: <span class="text-slate-700 dark:text-slate-200">{{ \App\Support\DateFormatter::forUser($selectedTicket['last_customer_message_at'] ?? null, 'M j, Y g:i a', 'Unknown') }}</span></div>
                                    <div>Last admin reply: <span class="text-slate-700 dark:text-slate-200">{{ \App\Support\DateFormatter::forUser($selectedTicket['last_admin_reply_at'] ?? null, 'M j, Y g:i a', 'Not yet') }}</span></div>
                                </div>

                                <div class="space-y-3 max-h-[420px] overflow-y-auto pr-1">
                                    @forelse (($selectedTicket['messages'] ?? []) as $entry)
                                        @php
                                            $senderType = strtolower((string) ($entry['sender_type'] ?? 'customer'));
                                            $isAdminMessage = $senderType === 'admin';
                                        @endphp
                                        <article class="rounded-lg border p-3 {{ $isAdminMessage ? 'border-indigo-300/50 bg-indigo-500/10' : 'border-slate-200/70 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/40' }}">
                                            <div class="flex items-center justify-between gap-2 mb-1">
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
                                            <div class="text-sm text-slate-800 dark:text-slate-200 whitespace-pre-wrap">{{ (string) ($entry['message'] ?? '') }}</div>
                                        </article>
                                    @empty
                                        <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-4 text-sm text-slate-500 dark:text-slate-400">
                                            No messages yet.
                                        </div>
                                    @endforelse
                                </div>

                                <div class="pt-2 border-t border-slate-200/70 dark:border-slate-800">
                                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Send Follow-Up</label>
                                    <textarea wire:model.defer="replyMessage" rows="5" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Add more details or respond to admin follow-up questions."></textarea>
                                    <x-input-error :messages="$errors->get('replyMessage')" class="mt-2" />
                                    <div class="mt-3">
                                        <button type="button" wire:click="sendReply" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500">
                                            Send Message
                                        </button>
                                    </div>
                                </div>
                            @else
                                <div class="rounded-lg border border-slate-200/70 dark:border-slate-800 p-6 text-sm text-slate-500 dark:text-slate-400">
                                    Select a ticket from the left to view details and thread history.
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
