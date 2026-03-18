<div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Email Notifications</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Send deploy outcomes to selected recipients.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="emailEnabled" class="rounded border-slate-300 dark:border-slate-700" />
                    Enable email notifications
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="emailIncludeOwner" class="rounded border-slate-300 dark:border-slate-700" />
                    Include project owner
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="emailDeploySuccess" class="rounded border-slate-300 dark:border-slate-700" />
                    Deploy succeeded
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="emailDeployFailed" class="rounded border-slate-300 dark:border-slate-700" />
                    Deploy failed
                </label>
            </div>

            <div>
                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Additional Recipients</label>
                <input type="text" wire:model.defer="emailRecipients" placeholder="comma-separated emails" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">These addresses receive deploy notifications in addition to the project owner.</p>
            </div>

            <div class="flex flex-wrap gap-3">
                <input type="email" wire:model.defer="testEmail" placeholder="test email" class="w-full sm:flex-1 rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                <button type="button" wire:click="sendTestEmail" class="px-4 py-2 rounded-md border border-slate-200 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                    Send Test Email
                </button>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 shadow-sm sm:rounded-xl border border-slate-200/60 dark:border-slate-800 p-6 space-y-6">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Webhook Notifications</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">Send JSON payloads to external systems (Discord, Slack, custom webhooks).</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="webhookEnabled" class="rounded border-slate-300 dark:border-slate-700" />
                    Enable webhooks
                </label>
                <div></div>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="webhookDeploySuccess" class="rounded border-slate-300 dark:border-slate-700" />
                    Deploy succeeded
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="webhookDeployFailed" class="rounded border-slate-300 dark:border-slate-700" />
                    Deploy failed
                </label>
            </div>

            <div>
                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Webhook URL</label>
                <input type="url" wire:model.defer="webhookUrl" placeholder="https://hooks.slack.com/..." class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
            </div>

            <div>
                <label class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Webhook Secret (optional)</label>
                <input type="password" wire:model.defer="webhookSecret" placeholder="Used for HMAC signature" class="mt-2 w-full rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
            </div>

            <div class="flex flex-wrap gap-3">
                <input type="url" wire:model.defer="testWebhookUrl" placeholder="test webhook url" class="w-full sm:flex-1 rounded-md border border-slate-200/70 bg-white/70 p-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                <button type="button" wire:click="sendTestWebhook" class="px-4 py-2 rounded-md border border-slate-200 text-sm text-slate-600 hover:text-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:text-white">
                    Send Test Webhook
                </button>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="button" wire:click="save" class="px-4 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500">
                Save Workflows
            </button>
        </div>
    </div>
</div>
