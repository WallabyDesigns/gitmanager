<?php

namespace App\Livewire\System;

use App\Models\Plugin as PluginModel;
use App\Services\Plugins\PluginManager;
use Illuminate\View\View;
use Livewire\Component;

class Plugins extends Component
{
    /** @var array<string, array<string, mixed>> */
    public array $pluginRecords = [];

    public function mount(PluginManager $manager): void
    {
        $this->loadPlugins($manager);
    }

    public function install(string $slug, PluginManager $manager): void
    {
        $plugin = $manager->find($slug);
        if ($plugin === null) {
            $this->dispatch('notify', type: 'error', message: "Plugin '{$slug}' not found.");
            return;
        }

        $record = PluginModel::firstOrCreate(['slug' => $slug]);
        $record->status        = PluginModel::STATUS_INSTALLING;
        $record->error_message = null;
        $record->save();

        try {
            $result = $plugin->install();
            $record->installed_version = $plugin->installedVersion();
            $record->status            = $result['success']
                ? PluginModel::STATUS_INSTALLED
                : PluginModel::STATUS_ERROR;
            $record->error_message = $result['success'] ? null : $result['message'];
            $record->save();

            $this->dispatch('notify',
                type: $result['success'] ? 'success' : 'error',
                message: $result['message']
            );
        } catch (\Throwable $e) {
            $record->status        = PluginModel::STATUS_ERROR;
            $record->error_message = $e->getMessage();
            $record->save();
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }

        $this->loadPlugins($manager);
    }

    public function update(string $slug, PluginManager $manager): void
    {
        $plugin = $manager->find($slug);
        if ($plugin === null) {
            $this->dispatch('notify', type: 'error', message: "Plugin '{$slug}' not found.");
            return;
        }

        $record = PluginModel::firstOrCreate(['slug' => $slug]);
        $record->status        = PluginModel::STATUS_UPDATING;
        $record->error_message = null;
        $record->save();

        try {
            $result = $plugin->update();
            $record->installed_version = $plugin->installedVersion();
            $record->status            = $result['success']
                ? PluginModel::STATUS_INSTALLED
                : PluginModel::STATUS_ERROR;
            $record->error_message = $result['success'] ? null : $result['message'];
            $record->save();

            $this->dispatch('notify',
                type: $result['success'] ? 'success' : 'error',
                message: $result['message']
            );
        } catch (\Throwable $e) {
            $record->status        = PluginModel::STATUS_ERROR;
            $record->error_message = $e->getMessage();
            $record->save();
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }

        $this->loadPlugins($manager);
    }

    public function uninstall(string $slug, PluginManager $manager): void
    {
        $plugin = $manager->find($slug);
        if ($plugin === null) {
            $this->dispatch('notify', type: 'error', message: "Plugin '{$slug}' not found.");
            return;
        }

        try {
            $result = $plugin->uninstall();

            $record = PluginModel::firstOrCreate(['slug' => $slug]);
            $record->installed_version = null;
            $record->status            = $result['success']
                ? PluginModel::STATUS_NOT_INSTALLED
                : PluginModel::STATUS_ERROR;
            $record->error_message = $result['success'] ? null : $result['message'];
            $record->save();

            $this->dispatch('notify',
                type: $result['success'] ? 'success' : 'error',
                message: $result['message']
            );
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }

        $this->loadPlugins($manager);
    }

    public function toggleAutoUpdate(string $slug): void
    {
        $record = PluginModel::firstOrCreate(['slug' => $slug]);
        $record->auto_update = ! $record->auto_update;
        $record->save();

        // Reflect the change in local state without a full reload.
        if (isset($this->pluginRecords[$slug])) {
            $this->pluginRecords[$slug]['autoUpdate'] = $record->auto_update;
        }
    }

    public function checkUpdates(PluginManager $manager): void
    {
        foreach ($manager->all() as $plugin) {
            try {
                $manager->checkAndMaybeUpdate($plugin);
            } catch (\Throwable $e) {
                $this->dispatch('notify', type: 'error', message: "Check failed for {$plugin->displayName()}: " . $e->getMessage());
            }
        }

        $this->loadPlugins($manager, checkVulnerabilities: true);
        $this->dispatch('notify', type: 'success', message: 'Plugin update check complete.');
    }

    public function render(): View
    {
        return view('livewire.system.plugins')
            ->layout('layouts.app', [
                'title' => 'Plugins',
                'header' => view('livewire.system.partials.header', [
                    'title' => 'System',
                    'subtitle' => 'Updates, security, settings, and platform services.',
                ]),
            ]);
    }

    private function loadPlugins(PluginManager $manager, bool $checkVulnerabilities = false): void
    {
        $this->pluginRecords = [];

        foreach ($manager->all() as $plugin) {
            $record = $manager->refreshRecord($plugin);
            $vulns  = ($checkVulnerabilities && $plugin->isInstalled())
                ? $plugin->checkVulnerabilities()
                : [];

            $this->pluginRecords[$plugin->slug()] = [
                'slug'             => $plugin->slug(),
                'displayName'      => $plugin->displayName(),
                'description'      => $plugin->description(),
                'category'         => $plugin->category(),
                'installedVersion' => $record->installed_version,
                'latestVersion'    => $record->latest_version,
                'status'           => $record->status,
                'autoUpdate'       => (bool) $record->auto_update,
                'lastCheckedAt'    => $record->last_checked_at?->diffForHumans(),
                'vulnerabilities'  => $vulns,
                'errorMessage'     => $record->error_message,
            ];
        }
    }
}
