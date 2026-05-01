<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\SecurityAlert;
use App\Services\GitHubService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;

class SecuritySync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Dependabot security alerts from GitHub.';

    /**
     * Execute the console command.
     */
    public function handle(GitHubService $github): int
    {
        if (! config('services.github.token')) {
            $this->warn('GITHUB_TOKEN is not configured. Skipping security sync.');

            return self::SUCCESS;
        }

        $projects = Project::query()
            ->whereNotNull('repo_url')
            ->get();

        foreach ($projects as $project) {
            try {
                $alerts = $github->getDependabotAlerts($project);
            } catch (ConnectionException $exception) {
                $this->warn('Dependabot sync failed for '.$project->name.': '.$exception->getMessage());

                continue;
            } catch (\Throwable $exception) {
                $this->warn('Dependabot sync failed for '.$project->name.': '.$exception->getMessage());

                continue;
            }
            $now = now();

            foreach ($alerts as $alert) {
                $alertId = $alert['id'] ?? $alert['number'] ?? null;
                if (! $alertId) {
                    continue;
                }

                $dependency = $alert['dependency']['package'] ?? [];
                $advisory = $alert['security_advisory'] ?? [];
                $vulnerability = $alert['security_vulnerability'] ?? [];

                SecurityAlert::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'github_alert_id' => $alertId,
                    ],
                    [
                        'state' => $alert['state'] ?? 'open',
                        'severity' => $advisory['severity'] ?? null,
                        'package_name' => $dependency['name'] ?? null,
                        'ecosystem' => $dependency['ecosystem'] ?? null,
                        'manifest_path' => $alert['dependency']['manifest_path'] ?? null,
                        'advisory_summary' => $advisory['summary'] ?? null,
                        'advisory_url' => $advisory['permalink'] ?? null,
                        'html_url' => $alert['html_url'] ?? null,
                        'fixed_in' => $vulnerability['first_patched_version']['identifier'] ?? null,
                        'dismissed_at' => $this->parseDate($alert['dismissed_at'] ?? null),
                        'fixed_at' => $this->parseDate($alert['fixed_at'] ?? null),
                        'alert_created_at' => $this->parseDate($alert['created_at'] ?? null),
                        'last_seen_at' => $now,
                    ]
                );
            }
        }

        $this->info('Security alerts synced.');

        return self::SUCCESS;
    }

    private function parseDate(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
