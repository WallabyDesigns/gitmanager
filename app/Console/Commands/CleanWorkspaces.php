<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanWorkspaces extends Command
{
    protected $signature = 'workspaces:clean {--dry-run : List orphaned directories without deleting}';

    protected $description = 'Remove FTP workspace and deploy-staging directories for deleted projects.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $activeIds = Project::query()->pluck('id')->flip()->all();
        $cleaned = 0;

        $directories = [
            'FTP workspaces' => rtrim(
                (string) config('gitmanager.ftp.workspace_path', storage_path('app/ftp-workspaces')),
                DIRECTORY_SEPARATOR
            ),
            'Deploy staging' => rtrim(
                (string) config('gitmanager.deploy_staging.path', storage_path('app/deploy-staging')),
                DIRECTORY_SEPARATOR
            ),
        ];

        foreach ($directories as $label => $basePath) {
            if (! is_dir($basePath)) {
                continue;
            }

            $entries = File::directories($basePath);

            foreach ($entries as $entry) {
                $dirname = basename($entry);

                if (! ctype_digit($dirname)) {
                    continue;
                }

                $projectId = (int) $dirname;

                if (isset($activeIds[$projectId])) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("[dry-run] Would delete {$label}: {$entry}");
                } else {
                    File::deleteDirectory($entry);
                    $this->line("Deleted {$label}: {$entry}");
                }

                $cleaned++;
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Cleaned {$cleaned} orphaned workspace directories.");

        return self::SUCCESS;
    }
}
