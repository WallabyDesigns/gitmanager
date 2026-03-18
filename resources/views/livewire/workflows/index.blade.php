<div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @include('livewire.workflows.partials.tabs')
        @include('livewire.partials.mail-warning', [
            'mailConfigured' => $mailConfigured,
            'showMailSettingsLink' => $showMailSettingsLink,
        ])

        <div>

            @if($tab === 'list')
                @if($workflows->isEmpty())
                    <div class="rounded-lg border border-dashed border-slate-300/70 dark:border-slate-700 p-6 text-sm text-slate-500 dark:text-slate-400">
                        No workflows yet. Switch to “Create Workflow” to add your first rule.
                    </div>
                @else
                    <div class="overflow-x-auto bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th class="text-left py-3 pr-4">Name</th>
                                    <th class="text-left py-3 pr-4">Trigger</th>
                                    <th class="text-left py-3 pr-4">Channel</th>
                                    <th class="text-left py-3 pr-4">Target</th>
                                    <th class="text-left py-3 pr-4">Status</th>
                                    <th class="text-right py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200/60 dark:divide-slate-800">
                                @foreach($workflows as $workflow)
                                    <tr class="text-slate-600  dark:text-slate-300">
                                        <td class="py-3 pr-4 font-medium text-slate-900 dark:text-slate-100">
                                            {{ $workflow->name }}
                                        </td>
                                        <td class="py-3 pr-4">
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs rounded-full border border-slate-200/70 dark:border-slate-700 px-2 py-0.5">
                                                    {{ $this->formatActionLabel($workflow->action) }}
                                                </span>
                                                <span class="text-xs rounded-full px-2 py-0.5 {{ $workflow->status === 'success' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400' }}">
                                                    {{ $statusOptions[$workflow->status] ?? ucfirst($workflow->status) }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <span class="text-xs rounded-full border border-slate-200/70 dark:border-slate-700 px-2 py-0.5">
                                                {{ $channelOptions[$workflow->channel] ?? ucfirst($workflow->channel) }}
                                            </span>
                                        </td>
                                        <td class="py-3 pr-4 text-xs text-slate-500 dark:text-slate-400">
                                            @if($workflow->channel === 'email')
                                                <div>Owner: {{ $workflow->include_owner ? 'Yes' : 'No' }}</div>
                                                <div>{{ $workflow->recipients ? $workflow->recipients : 'No extra recipients' }}</div>
                                            @else
                                                <div>{{ $workflow->webhook_url ?: 'No webhook url' }}</div>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-4">
                                            <button type="button" wire:click="toggleWorkflow({{ $workflow->id }})" class="text-xs px-2 py-1 rounded-md border border-slate-200/70 dark:border-slate-700">
                                                {{ $workflow->enabled ? 'Enabled' : 'Disabled' }}
                                            </button>
                                        </td>
                                        <td class="py-3 text-right">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" wire:click="startEdit({{ $workflow->id }})" class="text-xs px-2 py-1 rounded-md border border-slate-200/70 dark:border-slate-700">Edit</button>
                                                <button type="button" wire:click="deleteWorkflow({{ $workflow->id }})" class="text-xs px-2 py-1 rounded-md border border-rose-500/60 text-rose-400">Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>

        @if($tab === 'form')
            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $editingId ? 'Edit Workflow' : 'Create Workflow' }}</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Pick an action + outcome, then define how you want to be notified.</p>
                </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Workflow Name</label>
                    <input type="text" wire:model.defer="name" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" placeholder="Deploy Success Email" />
                    @error('name') <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" wire:model="enabled" class="rounded border-slate-300 dark:border-slate-700" />
                        Enabled
                    </label>
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Action</label>
                    <select wire:model.defer="action" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        @foreach($actionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('action') <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Outcome</label>
                    <select wire:model.defer="status" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status') <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Channel</label>
                    <select wire:model.defer="channel" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        @foreach($channelOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('channel') <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            @if($channel === 'email')
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" wire:model.defer="includeOwner" class="rounded border-slate-300 dark:border-slate-700" />
                        Include project owner
                    </label>
                    <div></div>
                    <div class="sm:col-span-2">
                        <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Additional Recipients</label>
                        <input type="text" wire:model.defer="recipients" placeholder="comma or newline separated emails" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Leave blank to notify only the project owner.</p>
                        @error('recipients') <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            @if($channel === 'webhook')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Webhook URL</label>
                        <input type="url" wire:model.defer="webhookUrl" placeholder="https://hooks.slack.com/..." class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                        @error('webhookUrl') <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Webhook Secret (optional)</label>
                        <input type="password" wire:model.defer="webhookSecret" placeholder="Used for HMAC signature" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Leave blank to keep the existing secret.</p>
                    </div>
                </div>
            @endif

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

        @if($tab === 'test')
            <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Test Delivery</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Send one-off tests using the current email/webhook settings.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <input type="email" wire:model.defer="testEmail" placeholder="test email" class="w-full sm:flex-1 rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                    <button type="button" wire:click="sendTestEmail" class="px-4 py-2 rounded-md border border-slate-200 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                        Send Test Email
                    </button>
                </div>
                <div class="flex flex-wrap gap-3">
                    <input type="url" wire:model.defer="testWebhookUrl" placeholder="test webhook url" class="w-full sm:flex-1 rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                    <button type="button" wire:click="sendTestWebhook" class="px-4 py-2 rounded-md border border-slate-200 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                        Send Test Webhook
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>
