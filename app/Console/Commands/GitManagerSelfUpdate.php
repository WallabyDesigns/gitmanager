<?php

namespace App\Console\Commands;

use App\Services\SelfUpdateService;
use Illuminate\Console\Command;

class GitManagerSelfUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gitmanager:self-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the Git Web Manager application from its repository.';

    /**
     * Execute the console command.
     */
    public function handle(SelfUpdateService $service): int
    {
        if (! config('gitmanager.self_update.enabled')) {
            $this->info('Self-update is disabled.');
            return self::SUCCESS;
        }

        $update = $service->updateSmart();

        return $update->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
