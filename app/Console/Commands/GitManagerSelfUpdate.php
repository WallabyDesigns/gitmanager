<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SelfUpdateService;
use Illuminate\Console\Command;

class GitManagerSelfUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gitmanager:self-update
        {--force : Force reset to the remote branch and discard local changes}
        {--user-id= : User id that triggered the update from the admin panel}';

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

        $user = null;
        $userId = (int) $this->option('user-id');
        if ($userId > 0) {
            $user = User::query()->find($userId);
        }

        if ($this->option('force')) {
            $update = $service->forceUpdate($user);
        } else {
            $status = $service->getUpdateStatus(true);
            if (($status['status'] ?? 'unknown') !== 'update-available') {
                $this->info(match ($status['status'] ?? 'unknown') {
                    'up-to-date' => 'Self-update skipped: application is already up to date.',
                    'blocked' => $status['deployment_guard']['message'] ?? 'Self-update skipped: pending update is blocked.',
                    default => 'Self-update skipped: no update is currently available.',
                });

                return self::SUCCESS;
            }

            $update = $service->updateSmart($user);
        }

        return $update->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
