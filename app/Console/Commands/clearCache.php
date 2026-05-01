<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class clearCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear app cache';

    /**
     * Execute the following console commands.
     *
     * php artisan clear-compiled
     * php artisan optimize:clear
     * php artisan optimize
     * composer dump-autoload -o
     */
    public function handle()
    {
        error_log("\n  [1/5] Clearing Cache...");
        Artisan::call('clear-compiled --quiet');
        Artisan::call('optimize:clear --quiet');

        error_log('  [2/5] Optimizing Cache...');
        Artisan::call('optimize --quiet');

        error_log('  [3/5] Building NPM Packages...');
        exec('npm run build');

        error_log('  [4/5] Dumping Autoload...');
        exec('composer dump-autoload -o --quiet');

        error_log("  [5/5] Complete!\n");
    }
}
