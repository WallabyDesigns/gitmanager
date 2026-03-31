<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Deployment;
use Illuminate\Support\Facades\Http;

class HealthCheckService
{
    public function __construct(
        private readonly LaravelDeploymentCheckService $laravelDeploymentCheckService,
        private readonly PermissionService $permissionService,
    ) {}

    public function checkHealth(Project $project, bool $log = false): string
    {
        $previousStatus = $project->health_status;
        $previousIssue = $project->health_issue_message;
        $healthLog = ['Health check started at '.now()->toDateTimeString().'.'];
        $laravelIssue = $this->runLaravelHealthChecks($project, $healthLog);

        $healthUrl = $this->resolveHealthUrl($project);

        if ($laravelIssue !== null) {
            $healthLog[] = 'Laravel checks failed: '.$laravelIssue;

            $enforceLaravelChecks = ! $project->ftp_enabled && ! $project->ssh_enabled;
            if ($enforceLaravelChecks || ! $healthUrl) {
                $status = 'na';
                $issueMessage = $laravelIssue;
                $logText = $this->formatHealthLog($healthLog);
                $this->saveHealthStatus($project, $status, $issueMessage, $logText);
                $this->maybeLogHealthCheck($project, $status, $issueMessage, $logText, $log, $previousStatus, $previousIssue);

                return $status;
            }
        }

        if (! $healthUrl) {
            $healthLog[] = 'Health check URL not configured.';
            $status = 'na';
            $issueMessage = null;
            $logText = $this->formatHealthLog($healthLog);
            $this->saveHealthStatus($project, $status, $issueMessage, $logText);
            $this->maybeLogHealthCheck($project, $status, $issueMessage, $logText, $log, $previousStatus, $previousIssue);

            return $status;
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

        $issueMessage = null;
        $logText = $this->formatHealthLog($healthLog);
        $this->saveHealthStatus($project, $status, $issueMessage, $logText);
        $this->maybeLogHealthCheck($project, $status, $issueMessage, $logText, $log, $previousStatus, $previousIssue);

        return $status;
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

    private function saveHealthStatus(Project $project, string $status, ?string $issueMessage, ?string $logText): void
    {
        $project->health_status = $status;
        $project->health_issue_message = $issueMessage;
        $project->health_log = $logText;
        $project->health_checked_at = now();
        $project->save();
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

    private function maybeLogHealthCheck(
        Project $project,
        string $status,
        ?string $issueMessage,
        ?string $logText,
        bool $forceLog,
        ?string $previousStatus,
        ?string $previousIssue
    ): void {
        if (! $this->shouldLogHealthCheck($forceLog, $status, $issueMessage, $previousStatus, $previousIssue)) {
            return;
        }

        $outputLog = $logText ?: 'Health check completed.';

        Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => null,
            'action' => 'health_check',
            'status' => $status === 'ok' ? 'success' : 'failed',
            'output_log' => $outputLog,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    private function shouldLogHealthCheck(
        bool $forceLog,
        string $status,
        ?string $issueMessage,
        ?string $previousStatus,
        ?string $previousIssue
    ): bool {
        if ($forceLog) {
            return true;
        }

        if ($previousStatus !== $status) {
            return true;
        }

        return ($previousIssue ?? '') !== ($issueMessage ?? '');
    }

    private function resolveHealthUrl(Project $project): ?string
    {
        $healthUrl = trim((string) $project->health_url);

        if ($healthUrl !== '') {
            if (str_starts_with($healthUrl, '/')) {
                $baseUrl = $this->resolveProjectBaseUrl($project);
                if ($baseUrl) {
                    return rtrim($baseUrl, '/').$healthUrl;
                }

                return null;
            }

            return $healthUrl;
        }

        $siteUrl = trim((string) $project->site_url);
        $laravelRoot = $this->findLaravelRoot($project->local_path);
        if ($siteUrl !== '') {
            if (($project->project_type ?? '') === 'laravel' || $laravelRoot) {
                return rtrim($siteUrl, '/').'/up';
            }

            return $siteUrl;
        }

        if ($laravelRoot) {
            $appUrl = $this->getLaravelAppUrl($laravelRoot);
            if ($appUrl) {
                return rtrim($appUrl, '/').'/up';
            }
        }

        return null;
    }

    private function resolveProjectBaseUrl(Project $project): ?string
    {
        $siteUrl = trim((string) $project->site_url);
        if ($siteUrl !== '') {
            return $siteUrl;
        }

        $laravelRoot = $this->findLaravelRoot($project->local_path);
        if ($laravelRoot) {
            return $this->getLaravelAppUrl($laravelRoot);
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
