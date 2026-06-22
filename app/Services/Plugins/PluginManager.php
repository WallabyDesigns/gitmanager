<?php

namespace App\Services\Plugins;

use App\Models\Plugin;

class PluginManager
{
    /** @return ManagedPlugin[] */
    public function all(): array
    {
        return [
            app(NodeRuntimePlugin::class),
            app(Pm2Plugin::class),
        ];
    }

    public function find(string $slug): ?ManagedPlugin
    {
        foreach ($this->all() as $plugin) {
            if ($plugin->slug() === $slug) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * Probe the live installed state and persist it to the DB record.
     */
    public function refreshRecord(ManagedPlugin $plugin): Plugin
    {
        $record = Plugin::firstOrCreate(['slug' => $plugin->slug()]);
        $record->installed_version = $plugin->installedVersion();
        $record->status = $plugin->isInstalled()
            ? Plugin::STATUS_INSTALLED
            : Plugin::STATUS_NOT_INSTALLED;
        $record->save();

        return $record;
    }

    /**
     * Fetch latest version from the upstream registry, persist it, and
     * trigger an auto-update when the DB record has auto_update enabled.
     */
    public function checkAndMaybeUpdate(ManagedPlugin $plugin): void
    {
        $record = Plugin::firstOrCreate(['slug' => $plugin->slug()]);

        $latest = $plugin->fetchLatestVersion();
        $record->latest_version     = $latest;
        $record->last_checked_at    = now();
        $record->installed_version  = $plugin->installedVersion();
        $record->status             = $plugin->isInstalled()
            ? Plugin::STATUS_INSTALLED
            : Plugin::STATUS_NOT_INSTALLED;
        $record->save();

        if (
            $record->auto_update
            && $plugin->isInstalled()
            && $latest !== null
            && $latest !== $plugin->installedVersion()
        ) {
            $record->status = Plugin::STATUS_UPDATING;
            $record->save();

            $result = $plugin->update();

            $record->installed_version = $plugin->installedVersion();
            $record->status            = $result['success']
                ? Plugin::STATUS_INSTALLED
                : Plugin::STATUS_ERROR;
            $record->error_message = $result['success'] ? null : $result['message'];
            $record->save();
        }
    }
}
