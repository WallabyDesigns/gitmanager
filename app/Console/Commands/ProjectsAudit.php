<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Services\DeploymentQueueService;
use App\Services\EditionService;
use App\Services\LicenseService;
use App\Services\SchedulerService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class ProjectsAudit extends Command
{
    protected $signature = 'projects:audit';

    protected $description = 'Queue enterprise dependency audits and automatic remediation for eligible projects.';

    public function handle(
        DeploymentQueueService $queue,
        SchedulerService $scheduler,
        SettingsService $settings,
        EditionService $edition,
        LicenseService $license,
    ): int {
        $scheduler->recordHeartbeat('schedule');

        if (! $this->auditsAreEnabled($settings, $edition, $license)) {
            $this->line('Enterprise vulnerability audits are disabled or unavailable.');

            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;
        foreach (Project::query()->get() as $project) {
            if ($this->isIneligible($project)) {
                $skipped++;

                continue;
            }

            $item = $queue->enqueue($project, 'audit_project', [
                'auto_fix' => true,
                'send_email' => true,
                'source' => 'scheduled_vulnerability_audit',
            ]);

            if ($item->wasRecentlyCreated) {
                $queued++;
            }
        }

        $this->info("Queued {$queued} vulnerability audit(s).".($skipped > 0 ? " Skipped {$skipped} project(s)." : ''));

        return self::SUCCESS;
    }

    private function auditsAreEnabled(SettingsService $settings, EditionService $edition, LicenseService $license): bool
    {
        return (bool) $settings->get('system.audit_enabled', false)
            && $edition->current() === EditionService::ENTERPRISE
            && $license->hasValidEnterpriseLicense();
    }

    private function isIneligible(Project $project): bool
    {
        if ($project->last_audit_at?->gte(now()->subHour())) {
            return true;
        }

        if ($project->permissions_locked && ! $project->ftp_enabled && ! $project->ssh_enabled) {
            return true;
        }

        return Deployment::query()->where('project_id', $project->id)->where('status', 'running')->exists()
            || DeploymentQueueItem::query()->where('project_id', $project->id)->where('status', 'running')->exists();
    }
}
