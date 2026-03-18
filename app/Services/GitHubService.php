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

        if (str_starts_with($repoUrl, 'git@')) {
            if (preg_match('/^git@([^:]+):(.+)$/', $repoUrl, $matches)) {
                $host = $matches[1] ?? null;
                $path = $matches[2] ?? null;
            } else {
                return null;
            }
        } elseif (str_contains($repoUrl, '://')) {
            $parts = parse_url($repoUrl);
            $host = $parts['host'] ?? null;
            $path = $parts['path'] ?? null;
        } else {
            return substr_count($repoUrl, '/') === 1 ? $repoUrl : null;
        }

        if (($host ?? '') !== 'github.com' || ! $path) {
            return null;
        }

        $path = trim($path, '/');
        $path = preg_replace('/\.git$/', '', $path);
        if (! $path) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== ''));
        if (count($segments) < 2) {
            return null;
        }

        return $segments[0].'/'.$segments[1];
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
