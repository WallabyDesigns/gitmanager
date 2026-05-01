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
        return $this->resolveRepoFullNameFromUrl((string) $project->repo_url);
    }

    public function resolveRepoFullNameFromUrl(string $repoUrl): ?string
    {
        $repoUrl = trim($repoUrl);
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

    /**
     * @return array{
     *   deployment_id: int,
     *   ref: string,
     *   state: string,
     *   environment: ?string,
     *   description: ?string,
     *   created_at: ?string,
     *   updated_at: ?string,
     *   target_url: ?string,
     *   log_url: ?string
     * }|null
     */
    public function getLatestDeploymentStatusForRef(string $repo, string $ref, ?string $environment = null): ?array
    {
        $repo = trim($repo);
        $ref = trim($ref);
        $environment = trim((string) $environment);

        if ($repo === '' || $ref === '') {
            return null;
        }

        $deployments = $this->fetchDeployments($repo, $ref, $environment);

        if ($deployments === [] && $environment !== '') {
            $deployments = $this->fetchDeployments($repo, $ref, null);
        }

        foreach ($deployments as $deployment) {
            $deploymentId = $deployment['id'] ?? null;
            if (! is_numeric($deploymentId)) {
                continue;
            }

            $statusResponse = $this->client()->get("/repos/{$repo}/deployments/{$deploymentId}/statuses", [
                'per_page' => 1,
            ]);

            if (! $statusResponse->successful()) {
                continue;
            }

            $statuses = $statusResponse->json();
            if (! is_array($statuses) || $statuses === []) {
                continue;
            }

            $status = $statuses[0] ?? null;
            if (! is_array($status)) {
                continue;
            }

            $state = trim((string) ($status['state'] ?? ''));
            if ($state === '') {
                continue;
            }

            $resolvedEnvironment = $status['environment'] ?? $deployment['environment'] ?? null;
            $resolvedEnvironment = is_string($resolvedEnvironment) ? trim($resolvedEnvironment) : null;
            $description = $status['description'] ?? null;
            $description = is_string($description) ? trim($description) : null;
            $createdAt = $status['created_at'] ?? null;
            $createdAt = is_string($createdAt) ? trim($createdAt) : null;
            $updatedAt = $status['updated_at'] ?? null;
            $updatedAt = is_string($updatedAt) ? trim($updatedAt) : null;
            $targetUrl = $status['target_url'] ?? null;
            $targetUrl = is_string($targetUrl) ? trim($targetUrl) : null;
            $logUrl = $status['log_url'] ?? null;
            $logUrl = is_string($logUrl) ? trim($logUrl) : null;

            return [
                'deployment_id' => (int) $deploymentId,
                'ref' => $ref,
                'state' => strtolower($state),
                'environment' => $resolvedEnvironment !== '' ? $resolvedEnvironment : null,
                'description' => $description !== '' ? $description : null,
                'created_at' => $createdAt !== '' ? $createdAt : null,
                'updated_at' => $updatedAt !== '' ? $updatedAt : null,
                'target_url' => $targetUrl !== '' ? $targetUrl : null,
                'log_url' => $logUrl !== '' ? $logUrl : null,
            ];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDeployments(string $repo, string $ref, ?string $environment = null): array
    {
        $query = [
            'sha' => $ref,
            'per_page' => 10,
        ];

        $environment = trim((string) $environment);
        if ($environment !== '') {
            $query['environment'] = $environment;
        }

        $response = $this->client()->get("/repos/{$repo}/deployments", $query);
        if (! $response->successful()) {
            return [];
        }

        $deployments = $response->json();

        return is_array($deployments) ? $deployments : [];
    }

    private function client(): PendingRequest
    {
        $token = config('services.github.token');
        $apiUrl = rtrim(config('services.github.api_url'), '/');
        $verify = (bool) config('services.github.verify_ssl', true);
        try {
            $setting = app(SettingsService::class)->get('system.github_ssl_verify');
            if ($setting !== null) {
                $verify = (bool) $setting;
            }
        } catch (\Throwable $exception) {
            // Ignore settings lookup failures.
        }

        return Http::baseUrl($apiUrl)
            ->withOptions([
                'verify' => $verify,
            ])
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
            ])
            ->when($token, fn (PendingRequest $request) => $request->withToken($token));
    }
}
