<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

class clearCache extends Command
{
    protected $signature = 'app:clear-cache';

    protected $description = 'Clear app cache';

    public function handle(): int
    {
        $this->line('Clearing framework caches...');
        Artisan::call('optimize:clear', [], $this->getOutput());

        $this->line('Rebuilding optimized caches...');
        Artisan::call('optimize', [], $this->getOutput());

        $this->line('Publishing assets...');
        Artisan::call('vendor:publish', ['--tag' => 'laravel-assets', '--force' => true], $this->getOutput());

        $this->line('Dumping autoload...');
        $process = new Process(['composer', 'dump-autoload', '-o', '--quiet'], base_path());
        $process->run();
        if (! $process->isSuccessful()) {
            $this->warn('composer dump-autoload failed: '.trim($process->getErrorOutput()));
        }

        $this->info('Cache cleared successfully.');

        return self::SUCCESS;
    }
}
