<div class="py-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @include('livewire.workflows.partials.tabs')
        @include('livewire.partials.mail-warning', [
            'mailConfigured' => $mailConfigured,
            'showMailSettingsLink' => $showMailSettingsLink,
        ])

        @if ($tab === 'list')
            <div class="space-y-4">
                @forelse ($workflows as $workflow)
                    <div class="rounded-xl border border-slate-200/60 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-6 space-y-4">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                                        {{ $workflow->name }}
                                    </h3>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium {{ $workflow->enabled ? 'bg-emerald-500/10 text-emerald-500 dark:text-emerald-300' : 'bg-slate-500/10 text-slate-500 dark:text-slate-300' }}">
                                        {{ $workflow->enabled ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </div>
                                <p class="text-sm text-slate-500 dark:text-slate-400">
                                    {{ count($workflow->triggerActions()) }} trigger {{ \Illuminate\Support\Str::plural('action', count($workflow->triggerActions())) }}
                                    and {{ count($this->workflowDestinations($workflow)) }} delivery {{ \Illuminate\Support\Str::plural('destination', count($this->workflowDestinations($workflow))) }}.
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="toggleWorkflow({{ $workflow->id }})" class="px-3 py-2 rounded-md border border-slate-200/70 dark:border-slate-700 text-sm text-slate-600 hover:text-slate-900 dark:text-slate-200 dark:hover:text-white">
                                    {{ $workflow->enabled ? 'Disable' : 'Enable' }}
                                </button>
                                <button type="button" wire:click="startEdit({{ $workflow->id }})" class="px-3 py-2 rounded-md border border-slate-200/70 dark:border-slate-700 text-sm text-slate-600 hover:text-slate-900 dark:text-slate-200 dark:hover:text-white">
                                    Edit
                                </button>
                                <button type="button" wire:click="deleteWorkflow({{ $workflow->id }})" class="px-3 py-2 rounded-md border border-rose-500/60 text-sm text-rose-500 hover:text-rose-400">
                                    Delete
                                </button>
                            </div>
                        </div>

                        <div class="grid gap-4 xl:grid-cols-[1fr,1.4fr]">
                            <div class="space-y-3">
                                <div>
                                    <div class="text-xs font-medium text-slate-500 dark:text-slate-400">Actions & Outcomes:</div>
                                    <div class="mt-2 gap-2 ">
                                        @foreach ($this->workflowActionLabels($workflow) as $label)
                                            <div class="inline-flex items-center rounded-full border border-slate-200/70 dark:border-slate-700 px-2.5 py-1 text-xs text-slate-600 dark:text-slate-200">
                                                {{ $label }}
                                            </div>
                                        @endforeach
                                        @foreach ($this->workflowStatusLabels($workflow) as $label)
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs {{ str_contains(strtolower($label), 'success') ? 'bg-emerald-500/10 text-emerald-500 dark:text-emerald-300' : 'bg-rose-500/10 text-rose-500 dark:text-rose-300' }}">
                                                {{ $label }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-xs uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">Delivery Destinations</div>
                                </div>
                                <div class="grid gap-3">
                                    @foreach ($this->workflowDestinations($workflow) as $delivery)
                                        <div class="rounded-lg border border-slate-200/60 dark:border-slate-800 bg-white/80 dark:bg-slate-900/70 p-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div class="text-sm font-medium text-slate-900 dark:text-slate-100">
                                                    {{ trim((string) ($delivery['name'] ?? '')) !== '' ? $delivery['name'] : $this->deliveryTypeLabel($delivery) }}
                                                </div>
                                                <span class="inline-flex items-center rounded-full border border-slate-200/70 dark:border-slate-700 px-2 py-0.5 text-[11px] text-slate-600 dark:text-slate-200">
                                                    {{ $this->deliveryTypeLabel($delivery) }}
                                                </span>
                                            </div>
                                            <div class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                                                {{ $this->deliveryTargetSummary($delivery) }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300/70 dark:border-slate-700 p-8 text-sm text-slate-500 dark:text-slate-400 bg-white/40 dark:bg-slate-900/40">
                        No workflows yet. Switch to “Create Workflow” to add your first automation rule.
                    </div>
                @endforelse
            </div>
        @endif

        @if ($tab === 'form')
            @php($eventPreview = $this->selectedEventPreview())
            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                            {{ $editingId ? 'Edit Workflow' : 'Create Workflow' }}
                        </h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Choose the events that should trigger this workflow, then add every delivery destination that should fire when it matches.
                        </p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" wire:model="enabled" class="rounded border-slate-300 dark:border-slate-700" />
                        Enabled
                    </label>
                </div>

                <div class="grid gap-4 lg:grid-cols-[1.2fr,0.8fr]">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Workflow Name</label>
                        <input type="text" wire:model.defer="name" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2.5 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Deployment Notifications" />
                        @error('name') <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 xl:grid-cols-[1.4fr,0.8fr]">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 space-y-4">
                        <div>
                            <div class="text-xs uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">Trigger Actions</div>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Define which actions will trigger this workflow.</p>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($actionOptions as $value => $label)
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" wire:model="selectedActions" value="{{ $value }}" class="mt-0.5 rounded border-slate-300 dark:border-slate-700" />
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('selectedActions') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 space-y-4">
                        <div>
                            <div class="text-xs uppercase tracking-[0.16em] text-slate-500">Outcomes</div>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Select whether this automation runs on success, failure, or both.</p>
                        </div>
                        <div class="grid gap-2">
                            @foreach ($statusOptions as $value => $label)
                                <label class="flex items-start gap-3  text-sm ">
                                    <input type="checkbox" wire:model="selectedStatuses" value="{{ $value }}" class="mt-0.5 rounded border-slate-300 dark:border-slate-700" />
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('selectedStatuses') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="flex flex-col gap-3  lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="text-xs uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">Delivery Destinations</div>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Add one or more destinations to fan out a single workflow across email and external applications.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="addEmailDelivery" class="px-3 py-2 rounded-md border border-slate-200/70 dark:border-slate-700 text-sm text-slate-600 hover:text-slate-900 dark:text-slate-200 dark:hover:text-white">
                                Add Email Destination
                            </button>
                            <button type="button" wire:click="addWebhookDelivery" class="px-3 py-2 rounded-md border border-indigo-400/50 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-300 dark:hover:text-indigo-200">
                                Add Webhook Destination
                            </button>
                        </div>
                    </div>

                    <div class="space-y-4">
                        @foreach ($deliveries as $index => $delivery)
                            <div wire:key="workflow-delivery-{{ $delivery['id'] ?? $index }}" class="rounded-xl border  bg-slate-950/60 border-slate-800 p-4 space-y-4">
                                <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                                    <div class="grid gap-3 sm:grid-cols-2 xl:flex-1">
                                        <div>
                                            <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Destination Type</label>
                                            <select wire:model.live="deliveries.{{ $index }}.type" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                                                @foreach ($channelOptions as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @error("deliveries.$index.type") <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Destination Name</label>
                                            <input type="text" wire:model.defer="deliveries.{{ $index }}.name" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="{{ ($delivery['type'] ?? 'email') === 'webhook' ? 'Release API' : 'On-call Team' }}" />
                                            @error("deliveries.$index.name") <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between gap-3">
                                        <span class="inline-flex items-center rounded-full border border-slate-200/70 dark:border-slate-700 px-2.5 py-1 text-xs text-slate-600 dark:text-slate-200">
                                            Destination {{ $index + 1 }}
                                        </span>
                                        <button type="button" wire:click="removeDelivery({{ $index }})" class="text-sm text-rose-500 hover:text-rose-400">
                                            Remove
                                        </button>
                                    </div>
                                </div>

                                @if (($delivery['type'] ?? 'email') === 'email')
                                    <div class="grid gap-4 lg:grid-cols-[0.7fr,1.3fr]">
                                        <div class="rounded-lg border border-slate-200/60 dark:border-slate-800 bg-white/80 dark:bg-slate-900/70 p-4">
                                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                                <input type="checkbox" wire:model="deliveries.{{ $index }}.include_owner" class="rounded border-slate-300 dark:border-slate-700" />
                                                Include project owner
                                            </label>
                                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                                Useful when the owner should always receive the same operational alert as the team.
                                            </p>
                                        </div>

                                        <div>
                                            <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Additional Recipients</label>
                                            <textarea wire:model.defer="deliveries.{{ $index }}.recipients" rows="4" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2.5 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="ops@example.com&#10;alerts@example.com"></textarea>
                                            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Separate addresses with commas or new lines.</p>
                                            @error("deliveries.$index.recipients") <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                @else
                                    <div class="grid gap-4 xl:grid-cols-[1.1fr,0.9fr]">
                                        <div class="space-y-4">
                                            <div>
                                                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Destination URL</label>
                                                <input type="url" wire:model.defer="deliveries.{{ $index }}.url" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2.5 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="https://example.com/api/workflows/deploy" />
                                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">This is the external endpoint Git Web Manager will POST to whenever the workflow matches.</p>
                                                @error("deliveries.$index.url") <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                                            </div>

                                            <div>
                                                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Signing Secret</label>
                                                <input type="password" wire:model.defer="deliveries.{{ $index }}.secret" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2.5 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Used for X-GWM-Signature HMAC validation" />
                                                @if (! empty($delivery['has_secret']))
                                                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">An existing secret is already stored. Leave blank to keep it.</p>
                                                @else
                                                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Optional, but recommended for validating requests from Git Web Manager.</p>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="rounded-lg border border-slate-200/60 dark:border-slate-800 bg-white/80 dark:bg-slate-900/70 p-4 space-y-3">
                                            <div class="text-xs uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">Webhook Payload</div>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ($eventPreview as $event)
                                                    <span class="inline-flex items-center rounded-full border border-slate-200/70 dark:border-slate-700 px-2 py-0.5 text-[11px] text-slate-600 dark:text-slate-200">
                                                        {{ $event }}
                                                    </span>
                                                @endforeach
                                            </div>
                                            <ul class="space-y-1 text-xs text-slate-500 dark:text-slate-400">
                                                <li>Includes project and deployment metadata.</li>
                                                <li>Includes workflow trigger and destination details.</li>
                                                <li>Includes app and project links for jumping back into the panel.</li>
                                            </ul>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @error('deliveries') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="saveWorkflow" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500">
                        {{ $editingId ? 'Save Changes' : 'Create Workflow' }}
                    </button>
                    <button type="button" wire:click="cancelEdit" class="px-4 py-2 rounded-md border border-slate-200 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                        Back to Workflows
                    </button>
                </div>
            </div>
        @endif

        @if ($tab === 'test')
            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-5">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Test Delivery</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Send one-off delivery tests before you save a workflow. Webhook tests will use the first configured webhook destination if you leave the URL blank here.
                    </p>
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    <div class="rounded-xl border border-slate-200/60 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-950/60 p-4 space-y-3">
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">Email Test</div>
                        <input type="email" wire:model.defer="testEmail" placeholder="alerts@example.com" class="w-full rounded-md border border-slate-200/70 bg-white/70 p-2.5 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                        <button type="button" wire:click="sendTestEmail" class="px-4 py-2 rounded-md border border-slate-200 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                            Send Test Email
                        </button>
                    </div>

                    <div class="rounded-xl border border-slate-200/60 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-950/60 p-4 space-y-3">
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">Webhook Test</div>
                        <input type="url" wire:model.defer="testWebhookUrl" placeholder="https://example.com/api/workflows/deploy" class="w-full rounded-md border border-slate-200/70 bg-white/70 p-2.5 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->selectedEventPreview() as $event)
                                <span class="inline-flex items-center rounded-full border border-slate-200/70 dark:border-slate-700 px-2 py-0.5 text-[11px] text-slate-600 dark:text-slate-200">
                                    {{ $event }}
                                </span>
                            @endforeach
                        </div>
                        <button type="button" wire:click="sendTestWebhook" class="px-4 py-2 rounded-md border border-slate-200 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                            Send Test Webhook
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
