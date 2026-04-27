<?php

namespace App\Livewire\Workflows;

use App\Models\Workflow;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Component;

class Index extends Component
{
    public ?int $editingId = null;
    public string $name = '';
    public bool $enabled = true;

    /**
     * @var array<int, string>
     */
    public array $selectedActions = [];

    /**
     * @var array<int, string>
     */
    public array $selectedStatuses = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $deliveries = [];

    public string $testWebhookUrl = '';
    public string $testEmail = '';

    public array $actionOptions = [
        'deploy' => 'Deploy',
        'rollback' => 'Rollback',
        'dependency_update' => 'Dependency Update',
        'preview_build' => 'Preview Build',
        'composer_install' => 'Composer Install',
        'composer_update' => 'Composer Update',
        'composer_audit' => 'Composer Audit',
        'npm_install' => 'Npm Install',
        'npm_update' => 'Npm Update',
        'npm_audit' => 'Npm Audit',
        'npm_audit_fix' => 'Npm Audit Fix',
        'npm_audit_fix_force' => 'Npm Audit Fix (Force)',
        'custom_command' => 'Custom Command',
        'app_clear_cache' => 'App Clear Cache',
    ];

    public array $statusOptions = [
        'success' => 'Success',
        'failed' => 'Failed',
    ];

    public array $channelOptions = [
        'email' => 'Email',
        'webhook' => 'Webhook',
    ];

    public string $tab = 'list';

    public function mount(): void
    {
        $this->resetForm();
    }

    public function render()
    {
        $settings = app(SettingsService::class);

        return view('livewire.workflows.index', [
            'workflows' => Workflow::query()->orderByDesc('created_at')->get(),
            'mailConfigured' => $settings->isMailConfigured(),
            'showMailSettingsLink' => auth()->user()?->isAdmin() ?? false,
        ])
            ->layout('layouts.app', [
                'title' => 'Workflows',
                'header' => view('livewire.workflows.partials.header'),
            ]);
    }

    public function saveWorkflow(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'selectedActions' => ['required', 'array', 'min:1'],
            'selectedActions.*' => ['required', 'string'],
            'selectedStatuses' => ['required', 'array', 'min:1'],
            'selectedStatuses.*' => ['required', 'string'],
            'deliveries' => ['required', 'array', 'min:1'],
            'deliveries.*.type' => ['required', 'string'],
            'deliveries.*.name' => ['nullable', 'string', 'max:120'],
            'deliveries.*.recipients' => ['nullable', 'string'],
            'deliveries.*.url' => ['nullable', 'url'],
            'deliveries.*.secret' => ['nullable', 'string'],
        ]);

        $actions = $this->normalizeSelections($this->selectedActions, $this->actionOptions);
        $statuses = $this->normalizeSelections($this->selectedStatuses, $this->statusOptions);

        if ($actions === []) {
            $this->addError('selectedActions', 'Select at least one trigger action.');

            return;
        }

        if ($statuses === []) {
            $this->addError('selectedStatuses', 'Select at least one trigger outcome.');

            return;
        }

        $workflow = $this->editingId
            ? Workflow::findOrFail($this->editingId)
            : new Workflow();

        $deliveries = $this->prepareDeliveriesForSave($workflow);
        if ($deliveries === []) {
            return;
        }

        $primaryDelivery = $deliveries[0];

        $workflow->name = trim($this->name);
        $workflow->enabled = $this->enabled;
        $workflow->action = $actions[0];
        $workflow->status = $statuses[0];
        $workflow->channel = count($deliveries) > 1 ? 'multi' : (string) ($primaryDelivery['type'] ?? 'email');
        $workflow->include_owner = (($primaryDelivery['type'] ?? 'email') === 'email')
            ? (bool) ($primaryDelivery['include_owner'] ?? false)
            : false;
        $workflow->recipients = (($primaryDelivery['type'] ?? 'email') === 'email')
            ? trim((string) ($primaryDelivery['recipients'] ?? ''))
            : null;
        $workflow->webhook_url = (($primaryDelivery['type'] ?? 'email') === 'webhook')
            ? trim((string) ($primaryDelivery['url'] ?? ''))
            : null;
        $workflow->webhook_secret = $this->legacyPrimaryWebhookSecret($workflow, $primaryDelivery);
        $workflow->trigger_actions = $actions;
        $workflow->trigger_statuses = $statuses;
        $workflow->deliveries = array_map(function (array $delivery): array {
            unset($delivery['secret_plain']);

            return $delivery;
        }, $deliveries);

        $workflow->save();

        $label = $this->editingId ? 'Workflow updated.' : 'Workflow created.';
        $this->resetForm();
        $this->tab = 'list';
        $this->dispatch('notify', message: $label);
    }

    public function sendTestEmail(SettingsService $settings): void
    {
        $this->validate([
            'testEmail' => ['required', 'email'],
        ]);

        $settings->applyMailConfig();

        Mail::raw('Git Web Manager workflow test email.', function ($message) {
            $message->to($this->testEmail)
                ->subject('Git Web Manager workflow test');
        });

        $this->dispatch('notify', message: 'Test email sent.');
    }

    public function sendTestWebhook(): void
    {
        if ($this->testWebhookUrl === '') {
            foreach ($this->deliveries as $delivery) {
                if (($delivery['type'] ?? null) === 'webhook' && trim((string) ($delivery['url'] ?? '')) !== '') {
                    $this->testWebhookUrl = trim((string) $delivery['url']);
                    break;
                }
            }
        }

        $this->validate([
            'testWebhookUrl' => ['required', 'url'],
        ]);

        $actions = $this->normalizeSelections($this->selectedActions, $this->actionOptions);
        $statuses = $this->normalizeSelections($this->selectedStatuses, $this->statusOptions);
        $workflowDeliveries = array_map(function (array $delivery): array {
            return [
                'type' => $delivery['type'] ?? 'email',
                'name' => trim((string) ($delivery['name'] ?? '')),
                'target' => ($delivery['type'] ?? '') === 'webhook'
                    ? trim((string) ($delivery['url'] ?? ''))
                    : $this->deliveryTargetSummary($delivery),
            ];
        }, $this->deliveries);

        $payload = [
            'event' => 'workflow.test',
            'event_key' => 'workflow_test',
            'event_standard' => 'workflow.test',
            'message' => 'Git Web Manager test webhook',
            'timestamp' => now()->toDateTimeString(),
            'workflow' => [
                'name' => trim($this->name) !== '' ? $this->name : 'Test Workflow',
                'actions' => $actions !== [] ? $actions : ['deploy'],
                'statuses' => $statuses !== [] ? $statuses : ['success'],
                'deliveries' => $workflowDeliveries,
            ],
            'links' => [
                'app' => route('projects.index'),
                'system_updates' => route('system.updates'),
            ],
        ];

        $request = Http::timeout(8);
        $secret = $this->firstWebhookSecretInput();
        if ($secret !== '') {
            $request = $request->withHeaders([
                'X-GWM-Signature' => hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), $secret),
            ]);
        }

        $response = $request->post($this->testWebhookUrl, $payload);
        $response->throw();

        $this->dispatch('notify', message: 'Test webhook sent (HTTP '.$response->status().').');
    }

    public function startEdit(int $workflowId): void
    {
        $workflow = Workflow::findOrFail($workflowId);

        $this->editingId = $workflow->id;
        $this->name = $workflow->name;
        $this->enabled = (bool) $workflow->enabled;
        $this->selectedActions = $workflow->triggerActions();
        $this->selectedStatuses = $workflow->triggerStatuses();
        $this->deliveries = $this->editorDeliveriesForWorkflow($workflow);
        $this->testWebhookUrl = '';
        $this->tab = 'form';
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
        $this->tab = 'list';
    }

    public function deleteWorkflow(int $workflowId): void
    {
        Workflow::query()->whereKey($workflowId)->delete();
        $this->dispatch('notify', message: 'Workflow deleted.');
    }

    public function toggleWorkflow(int $workflowId): void
    {
        $workflow = Workflow::findOrFail($workflowId);
        $workflow->enabled = ! $workflow->enabled;
        $workflow->save();
    }

    public function addEmailDelivery(): void
    {
        $this->deliveries[] = $this->defaultEditorDelivery('email');
    }

    public function addWebhookDelivery(): void
    {
        $this->deliveries[] = $this->defaultEditorDelivery('webhook');
    }

    public function removeDelivery(int $index): void
    {
        unset($this->deliveries[$index]);
        $this->deliveries = array_values($this->deliveries);

        if ($this->deliveries === []) {
            $this->deliveries[] = $this->defaultEditorDelivery('email');
        }
    }

    public function formatActionLabel(string $action): string
    {
        return Str::headline($action);
    }

    /**
     * @return array<int, string>
     */
    public function workflowActionLabels(Workflow $workflow): array
    {
        return array_map(fn (string $action): string => $this->actionOptions[$action] ?? $this->formatActionLabel($action), $workflow->triggerActions());
    }

    /**
     * @return array<int, string>
     */
    public function workflowStatusLabels(Workflow $workflow): array
    {
        return array_map(fn (string $status): string => $this->statusOptions[$status] ?? ucfirst($status), $workflow->triggerStatuses());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workflowDestinations(Workflow $workflow): array
    {
        return $workflow->deliveryDefinitions();
    }

    public function deliveryTypeLabel(array $delivery): string
    {
        return $this->channelOptions[$delivery['type'] ?? ''] ?? Str::headline((string) ($delivery['type'] ?? 'destination'));
    }

    public function deliveryTargetSummary(array $delivery): string
    {
        if (($delivery['type'] ?? null) === 'webhook') {
            return trim((string) ($delivery['url'] ?? '')) ?: 'No webhook URL configured';
        }

        $recipients = $this->splitRecipients((string) ($delivery['recipients'] ?? ''));
        $parts = [];

        if ((bool) ($delivery['include_owner'] ?? false)) {
            $parts[] = 'Project owner';
        }

        if ($recipients !== []) {
            $parts[] = count($recipients) === 1
                ? $recipients[0]
                : count($recipients).' extra recipients';
        }

        return $parts !== [] ? implode(' + ', $parts) : 'No recipients configured';
    }

    /**
     * @return array<int, string>
     */
    public function selectedEventPreview(): array
    {
        $actions = $this->normalizeSelections($this->selectedActions, $this->actionOptions);
        $statuses = $this->normalizeSelections($this->selectedStatuses, $this->statusOptions);

        $events = [];
        foreach ($actions as $action) {
            foreach ($statuses as $status) {
                $events[] = $action.'.'.$status;
            }
        }

        return array_values(array_unique($events));
    }

    public function setTab(string $tab): void
    {
        $this->tab = match ($tab) {
            'form' => 'form',
            'test' => 'test',
            default => 'list',
        };
    }

    private function resetForm(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->name = '';
        $this->enabled = true;
        $this->selectedActions = ['deploy'];
        $this->selectedStatuses = ['success'];
        $this->deliveries = [$this->defaultEditorDelivery('email')];
        $this->testWebhookUrl = '';
        $this->testEmail = '';
    }

    /**
     * @param  array<int, string>  $selected
     * @param  array<string, string>  $options
     * @return array<int, string>
     */
    private function normalizeSelections(array $selected, array $options): array
    {
        $values = array_map(static fn ($value): string => trim((string) $value), $selected);

        return array_values(array_unique(array_filter(
            $values,
            static fn (string $value): bool => $value !== '' && array_key_exists($value, $options)
        )));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prepareDeliveriesForSave(?Workflow $workflow = null): array
    {
        $existingDeliveries = collect($workflow?->deliveryDefinitions() ?? [])->keyBy(
            static fn (array $delivery): string => (string) ($delivery['id'] ?? '')
        );

        $prepared = [];
        $hasErrors = false;

        foreach (array_values($this->deliveries) as $index => $delivery) {
            $type = strtolower(trim((string) ($delivery['type'] ?? '')));

            if (! array_key_exists($type, $this->channelOptions)) {
                $this->addError("deliveries.$index.type", 'Invalid destination type selected.');
                $hasErrors = true;

                continue;
            }

            $id = trim((string) ($delivery['id'] ?? '')) ?: (string) Str::uuid();
            $name = trim((string) ($delivery['name'] ?? ''));

            if ($type === 'email') {
                $includeOwner = (bool) ($delivery['include_owner'] ?? true);
                $recipients = trim((string) ($delivery['recipients'] ?? ''));
                $invalidRecipients = $this->invalidRecipients($recipients);

                if ($invalidRecipients !== []) {
                    $this->addError("deliveries.$index.recipients", 'Invalid email address: '.implode(', ', $invalidRecipients));
                    $hasErrors = true;

                    continue;
                }

                if (! $includeOwner && $recipients === '') {
                    $this->addError("deliveries.$index.recipients", 'Provide at least one recipient or include the project owner.');
                    $hasErrors = true;

                    continue;
                }

                $prepared[] = [
                    'id' => $id,
                    'type' => 'email',
                    'name' => $name,
                    'include_owner' => $includeOwner,
                    'recipients' => $recipients,
                ];

                continue;
            }

            $url = trim((string) ($delivery['url'] ?? ''));
            if ($url === '') {
                $this->addError("deliveries.$index.url", 'Webhook URL is required.');
                $hasErrors = true;

                continue;
            }

            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                $this->addError("deliveries.$index.url", 'Provide a valid webhook URL.');
                $hasErrors = true;

                continue;
            }

            $secretInput = (string) ($delivery['secret'] ?? '');
            $existingSecret = $existingDeliveries->get($id)['secret_encrypted'] ?? null;

            $entry = [
                'id' => $id,
                'type' => 'webhook',
                'name' => $name,
                'url' => $url,
            ];

            if ($secretInput !== '') {
                $entry['secret_encrypted'] = Crypt::encryptString($secretInput);
                $entry['secret_plain'] = $secretInput;
            } elseif (is_string($existingSecret) && $existingSecret !== '') {
                $entry['secret_encrypted'] = $existingSecret;
            }

            $prepared[] = $entry;
        }

        return $hasErrors ? [] : array_values($prepared);
    }

    /**
     * @return array<int, string>
     */
    private function invalidRecipients(string $recipients): array
    {
        return array_values(array_filter(
            $this->splitRecipients($recipients),
            static fn (string $recipient): bool => filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
        ));
    }

    /**
     * @return array<int, string>
     */
    private function splitRecipients(string $recipients): array
    {
        $values = preg_split('/[,\r\n]+/', $recipients) ?: [];

        return array_values(array_filter(array_map('trim', $values), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function editorDeliveriesForWorkflow(Workflow $workflow): array
    {
        return array_map(function (array $delivery): array {
            return [
                'id' => $delivery['id'] ?? (string) Str::uuid(),
                'type' => $delivery['type'] ?? 'email',
                'name' => trim((string) ($delivery['name'] ?? '')),
                'include_owner' => (bool) ($delivery['include_owner'] ?? true),
                'recipients' => trim((string) ($delivery['recipients'] ?? '')),
                'url' => trim((string) ($delivery['url'] ?? '')),
                'secret' => '',
                'has_secret' => is_string($delivery['secret_encrypted'] ?? null) && trim((string) $delivery['secret_encrypted']) !== '',
            ];
        }, $workflow->deliveryDefinitions());
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultEditorDelivery(string $type): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'name' => '',
            'include_owner' => true,
            'recipients' => '',
            'url' => '',
            'secret' => '',
            'has_secret' => false,
        ];
    }

    private function firstWebhookSecretInput(): string
    {
        foreach ($this->deliveries as $delivery) {
            if (($delivery['type'] ?? null) === 'webhook') {
                return trim((string) ($delivery['secret'] ?? ''));
            }
        }

        return '';
    }

    private function legacyPrimaryWebhookSecret(Workflow $workflow, array $primaryDelivery): ?string
    {
        if (($primaryDelivery['type'] ?? null) !== 'webhook') {
            return null;
        }

        if (is_string($primaryDelivery['secret_plain'] ?? null) && trim((string) $primaryDelivery['secret_plain']) !== '') {
            return $primaryDelivery['secret_plain'];
        }

        $storedDelivery = collect($workflow->deliveryDefinitions())->first(
            fn (array $delivery): bool => (string) ($delivery['id'] ?? '') === (string) ($primaryDelivery['id'] ?? '')
        );

        $encrypted = $storedDelivery['secret_encrypted'] ?? $workflow->getRawOriginal('webhook_secret');
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }
}
