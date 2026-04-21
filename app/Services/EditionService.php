<?php

namespace App\Services;

class EditionService
{
    public const COMMUNITY = 'community';
    public const ENTERPRISE = 'enterprise';
    private const TESTING_OVERRIDE_KEY = 'system.testing.edition_override';
    private ?string $resolvedEdition = null;

    public function __construct(
        private readonly LicenseService $license,
        private readonly SettingsService $settings,
    ) {}

    public function current(): string
    {
        if ($this->resolvedEdition !== null) {
            return $this->resolvedEdition;
        }

        $edition = $this->normalize($this->license->currentLicensedEdition());

        if ($this->canSwapForTesting()) {
            $override = $this->normalizeOverride((string) $this->settings->get(self::TESTING_OVERRIDE_KEY, ''));
            if ($override !== null) {
                $edition = $override;
            }
        }

        $this->resolvedEdition = $edition;

        return $this->resolvedEdition;
    }

    public function label(?string $edition = null): string
    {
        return match ($this->normalize($edition ?? $this->current())) {
            self::ENTERPRISE => 'Enterprise Edition',
            default => 'Community Edition',
        };
    }

    public function canSwapForTesting(): bool
    {
        $class = \GitManagerEnterprise\Support\TestingEditionAccess::class;
        if (! class_exists($class) || ! method_exists($class, 'canUseTestingToggle')) {
            return false;
        }

        try {
            return (bool) $class::canUseTestingToggle((string) config('app.key', ''));
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function setTestingEdition(string $edition): void
    {
        if (! $this->canSwapForTesting()) {
            return;
        }

        $normalized = $this->normalize($edition);
        $this->settings->set(self::TESTING_OVERRIDE_KEY, $normalized);
        $this->resolvedEdition = $normalized;
    }

    public function clearTestingEdition(): void
    {
        $this->settings->set(self::TESTING_OVERRIDE_KEY, '');
        $this->resolvedEdition = null;
    }

    private function normalize(string $edition): string
    {
        return strtolower(trim($edition)) === self::ENTERPRISE
            ? self::ENTERPRISE
            : self::COMMUNITY;
    }

    private function normalizeOverride(string $edition): ?string
    {
        $candidate = strtolower(trim($edition));
        if ($candidate === self::ENTERPRISE) {
            return self::ENTERPRISE;
        }

        if ($candidate === self::COMMUNITY) {
            return self::COMMUNITY;
        }

        return null;
    }
}
