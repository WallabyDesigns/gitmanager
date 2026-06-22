<?php

namespace App\Console\Commands;

use App\Services\Plugins\PluginManager;
use Illuminate\Console\Command;

class PluginsCheckUpdates extends Command
{
    protected $signature   = 'plugins:check-updates';
    protected $description = 'Check for plugin updates and auto-install when enabled';

    public function handle(PluginManager $manager): int
    {
        foreach ($manager->all() as $plugin) {
            $this->info("Checking {$plugin->displayName()}...");
            try {
                $manager->checkAndMaybeUpdate($plugin);
                $this->line("  Done.");
            } catch (\Throwable $e) {
                $this->error("  Failed: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
