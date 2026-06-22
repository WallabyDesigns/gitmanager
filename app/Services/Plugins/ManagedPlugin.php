<?php

namespace App\Services\Plugins;

interface ManagedPlugin
{
    public function slug(): string;

    public function displayName(): string;

    public function description(): string;

    /** @return string 'runtime' | 'process-manager' */
    public function category(): string;

    public function isInstalled(): bool;

    public function installedVersion(): ?string;

    /** Makes an HTTP call to the upstream registry. Returns null on failure. */
    public function fetchLatestVersion(): ?string;

    /** @return array{success: bool, message: string} */
    public function install(): array;

    /** @return array{success: bool, message: string} */
    public function update(): array;

    /** @return array{success: bool, message: string} */
    public function uninstall(): array;

    /** @return string[] List of vulnerability / warning strings. Empty when clean. */
    public function checkVulnerabilities(): array;
}
