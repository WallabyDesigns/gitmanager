<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\GitHubService;
use Illuminate\Console\Command;

class DependabotAutoMerge extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dependabot:auto-merge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically merge Dependabot pull requests when checks pass.';

    /**
     * Execute the console command.
     */
    public function handle(GitHubService $github): int
    {
        if (! config('services.github.token')) {
            $this->warn('GITHUB_TOKEN is not configured. Skipping auto-merge.');

            return self::SUCCESS;
        }

        $projects = Project::query()
            ->whereNotNull('repo_url')
            ->where('allow_dependency_updates', true)
            ->get();

        foreach ($projects as $project) {
            $repo = $github->resolveRepoFullName($project);
            if (! $repo) {
                continue;
            }

            $pulls = $github->getDependabotPullRequests($project);
            foreach ($pulls as $pull) {
                $number = $pull['number'] ?? null;
                if (! $number) {
                    continue;
                }

                $details = $github->getPullRequest($repo, $number);
                if (! $details) {
                    continue;
                }

                if (($details['mergeable_state'] ?? null) !== 'clean') {
                    continue;
                }

                if ($github->mergePullRequest($repo, $number)) {
                    $this->info("Merged Dependabot PR #{$number} for {$repo}.");
                }
            }
        }

        return self::SUCCESS;
    }
}
