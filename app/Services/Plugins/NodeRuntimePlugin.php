<?php

namespace App\Services\Plugins;

use App\Services\NodeInstallService;

class NodeRuntimePlugin implements ManagedPlugin
{
    public function __construct(
        private readonly NodeInstallService $nodeInstall,
    ) {}

    public function slug(): string
    {
        return 'node-runtime';
    }

    public function displayName(): string
    {
        return 'Node.js Runtime';
    }

    public function description(): string
    {
        return 'Bundled Node.js LTS runtime used to run JavaScript tools and process managers within the application.';
    }

    public function category(): string
    {
        return 'runtime';
    }

    public function isInstalled(): bool
    {
        return $this->nodeInstall->isInstalled();
    }

    public function installedVersion(): ?string
    {
        $detected = $this->nodeInstall->detect();

        return $detected['found'] ? ($detected['version'] ?? null) : null;
    }

    public function fetchLatestVersion(): ?string
    {
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 10]]);
            $json = @file_get_contents('https://nodejs.org/dist/index.json', false, $ctx);

            if ($json === false) {
                return null;
            }

            $releases = json_decode($json, true);
            if (! is_array($releases)) {
                return null;
            }

            foreach ($releases as $release) {
                if (isset($release['lts']) && $release['lts'] !== false) {
                    return $release['version'] ?? null;
                }
            }
        } catch (\Throwable) {
            // Network or parse failure — return null gracefully.
        }

        return null;
    }

    public function install(): array
    {
        return $this->nodeInstall->install();
    }

    public function update(): array
    {
        // Re-installing overwrites the existing runtime.
        return $this->nodeInstall->install();
    }

    public function uninstall(): array
    {
        return $this->nodeInstall->uninstall();
    }

    public function checkVulnerabilities(): array
    {
        if (! $this->isInstalled()) {
            return [];
        }

        $installed = $this->installedVersion();
        $latest    = $this->fetchLatestVersion();

        if ($installed === null || $latest === null) {
            return [];
        }

        if ($installed !== $latest) {
            return ['Installed version may have known vulnerabilities. Update to latest LTS to stay secure.'];
        }

        return [];
    }
}
