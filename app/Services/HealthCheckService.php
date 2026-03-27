<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Http;

class HealthCheckService
{
    public function __construct(
        private readonly LaravelDeploymentCheckService $laravelDeploymentCheckService,
        private readonly PermissionService $permissionService,
    ) {}

    public function checkHealth(Project $project): string
    {
        $healthLog = [];
        $laravelIssue = $this->runLaravelHealthChecks($project, $healthLog);

        $healthUrl = $this->resolveHealthUrl($project);

        if ($laravelIssue !== null) {
            $healthLog[] = 'Laravel checks failed: '.$laravelIssue;

            return $this->saveHealthStatus($project, 'na', $laravelIssue, $healthLog);
        }

        if (! $healthUrl) {
            $healthLog[] = 'Health check URL not configured.';

            return $this->saveHealthStatus($project, 'na', null, $healthLog);
        }

        try {
            $response = Http::timeout(10)->get($healthUrl);
            if ($response->successful()) {
                $healthLog[] = 'HTTP health check OK ('.$response->status().').';
                $status = 'ok';
            } else {
                $healthLog[] = 'HTTP health check failed ('.$response->status().').';
                $status = 'na';
            }
        } catch (\Throwable $exception) {
            $healthLog[] = 'HTTP health check failed: '.$exception->getMessage();
            $status = 'na';
        }

        return $this->saveHealthStatus($project, $status, null, $healthLog);
    }

    private function runLaravelHealthChecks(Project $project, array &$output): ?string
    {
        $path = (string) $project->local_path;

        try {
            $this->laravelDeploymentCheckService->run($project, $path, $output);

            return null;
        } catch (\Throwable $exception) {
            $output[] = 'Laravel checks failed: '.$exception->getMessage();
            $output[] = 'Attempting Laravel repair before retry.';
            $this->attemptLaravelRepair($project, $path, $output);
        }

        try {
            $output[] = 'Retrying Laravel checks after repair.';
            $this->laravelDeploymentCheckService->run($project, $path, $output);

            return null;
        } catch (\Throwable $retryException) {
            $output[] = 'Laravel checks failed after repair: '.$retryException->getMessage();

            return $retryException->getMessage();
        }
    }

    private function attemptLaravelRepair(Project $project, string $path, array &$output): void
    {
        try {
            $this->permissionService->attemptFixPermissions($project, $path, $output, false);
        } catch (\Throwable $exception) {
            $output[] = 'Laravel repair attempt failed: '.$exception->getMessage();
        }
    }

    private function saveHealthStatus(Project $project, string $status, ?string $issueMessage, array $log): string
    {
        $project->health_status = $status;
        $project->health_issue_message = $issueMessage;
        $project->health_log = $this->formatHealthLog($log);
        $project->health_checked_at = now();
        $project->save();

        return $status;
    }

    private function formatHealthLog(array $log): ?string
    {
        $lines = [];
        foreach ($log as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $lines[] = $line;
        }

        if ($lines === []) {
            return null;
        }

        $maxLines = 200;
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return implode("\n", $lines);
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
