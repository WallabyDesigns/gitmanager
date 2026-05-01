<?php

namespace App\Services;

class EditionService
{
    public const COMMUNITY = 'community';

    public const ENTERPRISE = 'enterprise';

    private ?string $resolvedEdition = null;

    public function __construct(
        private readonly LicenseService $license,
    ) {}

    public function current(): string
    {
        if ($this->resolvedEdition !== null) {
            return $this->resolvedEdition;
        }

        $this->resolvedEdition = $this->normalize($this->license->currentLicensedEdition());

        return $this->resolvedEdition;
    }

    public function label(?string $edition = null): string
    {
        return match ($this->normalize($edition ?? $this->current())) {
            self::ENTERPRISE => 'Enterprise Edition',
            default => 'Community Edition',
        };
    }

    private function normalize(string $edition): string
    {
        return strtolower(trim($edition)) === self::ENTERPRISE
            ? self::ENTERPRISE
            : self::COMMUNITY;
    }
}
