<?php

namespace App\Livewire\System;

use App\Models\Plugin as PluginModel;
use App\Services\EnvManagerService;
use App\Services\OctaneInstanceService;
use App\Services\Plugins\PluginManager;
use App\Services\RuntimePluginStatusService;
use App\Services\RustExecutorInstanceService;
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
        $record->status = PluginModel::STATUS_INSTALLING;
        $record->error_message = null;
        $record->save();

        try {
            $result = $plugin->install();
            $record->installed_version = $plugin->installedVersion();
            $record->status = $result['success']
                ? PluginModel::STATUS_INSTALLED
                : PluginModel::STATUS_ERROR;
            $record->error_message = $result['success'] ? null : $result['message'];
            $record->save();

            $this->dispatch('notify',
                type: $result['success'] ? 'success' : 'error',
                message: $result['message']
            );
        } catch (\Throwable $e) {
            $record->status = PluginModel::STATUS_ERROR;
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
        $record->status = PluginModel::STATUS_UPDATING;
        $record->error_message = null;
        $record->save();

        try {
            $result = $plugin->update();
            $record->installed_version = $plugin->installedVersion();
            $record->status = $result['success']
                ? PluginModel::STATUS_INSTALLED
                : PluginModel::STATUS_ERROR;
            $record->error_message = $result['success'] ? null : $result['message'];
            $record->save();

            $this->dispatch('notify',
                type: $result['success'] ? 'success' : 'error',
                message: $result['message']
            );
        } catch (\Throwable $e) {
            $record->status = PluginModel::STATUS_ERROR;
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
            $record->status = $result['success']
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
                $this->dispatch('notify', type: 'error', message: "Check failed for {$plugin->displayName()}: ".$e->getMessage());
            }
        }

        $this->loadPlugins($manager, checkVulnerabilities: true);
        $this->dispatch('notify', type: 'success', message: 'Plugin update check complete.');
    }

    public function startOctane(OctaneInstanceService $octane): void
    {
        $result = $octane->startInBackground();
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function stopOctane(OctaneInstanceService $octane): void
    {
        $result = $octane->stopInBackground();
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function restartOctane(OctaneInstanceService $octane): void
    {
        $result = $octane->restartInBackground();
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function startRustExecutor(RustExecutorInstanceService $executor): void
    {
        $result = $executor->startInBackground();
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function stopRustExecutor(RustExecutorInstanceService $executor): void
    {
        $result = $executor->stopInBackground();
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function restartRustExecutor(RustExecutorInstanceService $executor): void
    {
        $result = $executor->restartInBackground();
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function activateReverb(EnvManagerService $environment): void
    {
        $status = app(RuntimePluginStatusService::class)->reverb();

        if (! $status['installed']) {
            $this->dispatch('notify', type: 'error', message: __('Laravel Reverb is not installed in this application build.'));

            return;
        }

        if (! $status['credentials_configured']) {
            $this->dispatch('notify', type: 'error', message: __('Configure the Reverb app ID, key, secret, and host in Environment Config before activating broadcasting.'));

            return;
        }

        $environment->set('BROADCAST_CONNECTION', 'reverb');
        config()->set('broadcasting.default', 'reverb');

        $this->dispatch('notify', type: 'success', message: __('Reverb is now the broadcast connection. Restart the Reverb server and long-running PHP processes to apply the change everywhere.'));
        $this->dispatch('$refresh');
    }

    public function render(OctaneInstanceService $octane, RustExecutorInstanceService $executor, RuntimePluginStatusService $runtimePlugins): View
    {
        return view('livewire.system.plugins')
            ->with([
                'octaneStatus' => $octane->status(),
                'reverbStatus' => $runtimePlugins->reverb(),
                'rustExecutorStatus' => $executor->status(),
            ])
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
            $vulns = ($checkVulnerabilities && $plugin->isInstalled())
                ? $plugin->checkVulnerabilities()
                : [];

            $this->pluginRecords[$plugin->slug()] = [
                'slug' => $plugin->slug(),
                'displayName' => $plugin->displayName(),
                'description' => $plugin->description(),
                'category' => $plugin->category(),
                'installedVersion' => $record->installed_version,
                'latestVersion' => $record->latest_version,
                'status' => $record->status,
                'autoUpdate' => (bool) $record->auto_update,
                'lastCheckedAt' => $record->last_checked_at?->diffForHumans(),
                'vulnerabilities' => $vulns,
                'errorMessage' => $record->error_message,
            ];
        }
    }
}
