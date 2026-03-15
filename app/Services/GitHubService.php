<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubService
{
    public function getDependabotAlerts(Project $project): array
    {
        $repo = $this->resolveRepoFullName($project);
        if (! $repo) {
            return [];
        }

        $alerts = [];
        $page = 1;

        do {
            $response = $this->client()->get("/repos/{$repo}/dependabot/alerts", [
                'state' => 'all',
                'per_page' => 100,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                break;
            }

            $chunk = $response->json();
            if (! is_array($chunk) || empty($chunk)) {
                break;
            }

            $alerts = array_merge($alerts, $chunk);
            $page++;
        } while (count($chunk) === 100);

        return $alerts;
    }

    public function getDependabotPullRequests(Project $project): array
    {
        $repo = $this->resolveRepoFullName($project);
        if (! $repo) {
            return [];
        }

        $response = $this->client()->get("/repos/{$repo}/pulls", [
            'state' => 'open',
            'per_page' => 100,
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->filter(fn ($pr) => ($pr['user']['login'] ?? '') === 'dependabot[bot]')
            ->values()
            ->all();
    }

    public function getPullRequest(string $repo, int $number): ?array
    {
        $response = $this->client()->get("/repos/{$repo}/pulls/{$number}");
        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    public function mergePullRequest(string $repo, int $number): bool
    {
        $response = $this->client()->put("/repos/{$repo}/pulls/{$number}/merge", [
            'merge_method' => 'squash',
        ]);

        return $response->successful();
    }

    public function resolveRepoFullName(Project $project): ?string
    {
        $repoUrl = trim((string) $project->repo_url);
        if ($repoUrl === '') {
            return null;
        }

        if (str_starts_with($repoUrl, 'git@github.com:')) {
            $repoUrl = str_replace('git@github.com:', 'https://github.com/', $repoUrl);
        }

        $repoUrl = preg_replace('/\.git$/', '', $repoUrl);
        if (! $repoUrl) {
            return null;
        }

        $parts = parse_url($repoUrl);
        if (! $parts || ($parts['host'] ?? '') !== 'github.com') {
            return null;
        }

        $path = trim($parts['path'] ?? '', '/');
        if (! $path || substr_count($path, '/') !== 1) {
            return null;
        }

        return $path;
    }

    private function client(): PendingRequest
    {
        $token = config('services.github.token');
        $apiUrl = rtrim(config('services.github.api_url'), '/');

        return Http::baseUrl($apiUrl)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
            ])
            ->when($token, fn (PendingRequest $request) => $request->withToken($token));
    }
}
