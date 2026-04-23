<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Workflow extends Model
{
    protected $fillable = [
        'name',
        'action',
        'status',
        'channel',
        'enabled',
        'include_owner',
        'recipients',
        'webhook_url',
        'webhook_secret',
        'trigger_actions',
        'trigger_statuses',
        'deliveries',
    ];

    protected $casts = [
        'enabled' => 'bool',
        'include_owner' => 'bool',
        'webhook_secret' => 'encrypted',
        'trigger_actions' => 'array',
        'trigger_statuses' => 'array',
        'deliveries' => 'array',
    ];

    /**
     * @return array<int, string>
     */
    public function triggerActions(): array
    {
        $actions = $this->normalizeStringArray($this->trigger_actions);

        if ($actions !== []) {
            return $actions;
        }

        $legacyAction = trim((string) ($this->action ?? ''));

        return $legacyAction !== '' ? [$legacyAction] : ['deploy'];
    }

    /**
     * @return array<int, string>
     */
    public function triggerStatuses(): array
    {
        $statuses = $this->normalizeStringArray($this->trigger_statuses);

        if ($statuses !== []) {
            return $statuses;
        }

        $legacyStatus = trim((string) ($this->status ?? ''));

        return $legacyStatus !== '' ? [$legacyStatus] : ['success'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deliveryDefinitions(): array
    {
        $deliveries = [];

        foreach (is_array($this->deliveries) ? $this->deliveries : [] as $delivery) {
            $normalized = $this->normalizeDeliveryDefinition($delivery);

            if ($normalized !== null) {
                $deliveries[] = $normalized;
            }
        }

        if ($deliveries !== []) {
            return $deliveries;
        }

        $legacyChannel = strtolower(trim((string) ($this->channel ?? 'email')));

        if ($legacyChannel === 'webhook') {
            return [[
                'id' => 'legacy-webhook-'.$this->getKey(),
                'type' => 'webhook',
                'name' => '',
                'url' => trim((string) ($this->webhook_url ?? '')),
                'secret_encrypted' => $this->getRawOriginal('webhook_secret'),
            ]];
        }

        return [[
            'id' => 'legacy-email-'.$this->getKey(),
            'type' => 'email',
            'name' => '',
            'include_owner' => (bool) $this->include_owner,
            'recipients' => trim((string) ($this->recipients ?? '')),
        ]];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = array_map(
            static fn ($value): string => trim((string) $value),
            $values
        );

        return array_values(array_unique(array_filter($normalized, static fn (string $value): bool => $value !== '')));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeDeliveryDefinition(mixed $delivery): ?array
    {
        if (! is_array($delivery)) {
            return null;
        }

        $type = strtolower(trim((string) ($delivery['type'] ?? '')));
        if (! in_array($type, ['email', 'webhook'], true)) {
            return null;
        }

        $normalized = [
            'id' => trim((string) ($delivery['id'] ?? '')) ?: (string) Str::uuid(),
            'type' => $type,
            'name' => trim((string) ($delivery['name'] ?? '')),
        ];

        if ($type === 'email') {
            $normalized['include_owner'] = (bool) ($delivery['include_owner'] ?? true);
            $normalized['recipients'] = trim((string) ($delivery['recipients'] ?? ''));

            return $normalized;
        }

        $normalized['url'] = trim((string) ($delivery['url'] ?? ''));

        $secret = $delivery['secret_encrypted'] ?? null;
        if (is_string($secret) && trim($secret) !== '') {
            $normalized['secret_encrypted'] = $secret;
        }

        return $normalized;
    }
}
