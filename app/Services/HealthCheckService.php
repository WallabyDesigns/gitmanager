<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Http;

class HealthCheckService
{
    public function __construct(
        private readonly LaravelDeploymentCheckService $laravelDeploymentCheckService,
    ) {}

    public function checkHealth(Project $project): string
    {
        $laravelIssue = $this->runLaravelHealthChecks($project);

        $healthUrl = $this->resolveHealthUrl($project);

        if ($laravelIssue !== null) {
            $project->health_status = 'na';
            $project->health_issue_message = $laravelIssue;
            $project->health_checked_at = now();
            $project->save();

            return 'na';
        }

        if (! $healthUrl) {
            $project->health_status = 'na';
            $project->health_issue_message = null;
            $project->health_checked_at = now();
            $project->save();

            return 'na';
        }

        try {
            $response = Http::timeout(10)->get($healthUrl);
            $status = $response->successful() ? 'ok' : 'na';
        } catch (\Throwable $exception) {
            $status = 'na';
        }

        $project->health_status = $status;
        $project->health_issue_message = null;
        $project->health_checked_at = now();
        $project->save();

        return $status;
    }

    private function runLaravelHealthChecks(Project $project): ?string
    {
        $output = [];
        try {
            $this->laravelDeploymentCheckService->run($project, (string) $project->local_path, $output);
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function resolveHealthUrl(Project $project): ?string
    {
        $healthUrl = trim((string) $project->health_url);

        if ($healthUrl !== '') {
            if (str_starts_with($healthUrl, '/')) {
                $laravelRoot = $this->findLaravelRoot($project->local_path);
                $appUrl = $laravelRoot ? $this->getLaravelAppUrl($laravelRoot) : null;
                if ($appUrl) {
                    return rtrim($appUrl, '/').$healthUrl;
                }

                return null;
            }

            return $healthUrl;
        }

        $laravelRoot = $this->findLaravelRoot($project->local_path);
        if ($laravelRoot) {
            $appUrl = $this->getLaravelAppUrl($laravelRoot);
            if ($appUrl) {
                return rtrim($appUrl, '/').'/up';
            }
        }

        return null;
    }

    private function findLaravelRoot(string $path): ?string
    {
        $cursor = $path;

        while (true) {
            if (is_file($cursor.DIRECTORY_SEPARATOR.'artisan')) {
                return $cursor;
            }

            $parent = dirname($cursor);
            if (! $parent || $parent === $cursor) {
                break;
            }

            $cursor = $parent;
        }

        return null;
    }

    private function getLaravelAppUrl(string $path): ?string
    {
        return $this->getLaravelEnvValue($path, 'APP_URL');
    }

    private function getLaravelEnvValue(string $path, string $key): ?string
    {
        $envPath = $path.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($envPath)) {
            return null;
        }

        $prefix = $key.'=';
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_starts_with($line, $prefix)) {
                continue;
            }

            $value = trim(substr($line, strlen($prefix)));
            $value = trim($value, "\"'");

            return $value !== '' ? $value : null;
        }

        return null;
    }
}
