<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Deployment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class HealthCheckService
{
    private const EMAIL_COOLDOWN_MINUTES = 10;

    /**
     * @var array<int, array{project: Project, previous: string, current: string, issue: string|null, url: string|null, checked_at: string}>
     */
    private array $pendingAlerts = [];

    public function __construct(
        private readonly LaravelDeploymentCheckService $laravelDeploymentCheckService,
        private readonly PermissionService $permissionService,
        private readonly SettingsService $settings,
    ) {}

    public function checkHealth(Project $project, bool $log = false, bool $notifyOnFailure = false): string
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
                $this->maybeNotifyHealthChange($project, $previousStatus, $status, $issueMessage, $notifyOnFailure);
                $this->maybeLogHealthCheck($project, $status, $issueMessage, $logText, $log, $previousStatus, $previousIssue);

                return $status;
            }
        }

        if (! $healthUrl) {
            return 'na';
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
        $this->maybeNotifyHealthChange($project, $previousStatus, $status, $issueMessage, $notifyOnFailure);
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

    private function maybeNotifyHealthChange(
        Project $project,
        ?string $previousStatus,
        string $currentStatus,
        ?string $issueMessage,
        bool $notifyOnFailure
    ): void {
        if (! $previousStatus || $previousStatus === $currentStatus) {
            return;
        }

        if ($previousStatus !== 'ok' || $currentStatus === 'ok') {
            return;
        }

        if (! $notifyOnFailure) {
            return;
        }

        if (! $this->settings->isMailConfigured()) {
            return;
        }

        if (! $this->settings->get('workflows.email.enabled', true)) {
            return;
        }

        if (! $this->settings->get('system.health_email_enabled', true)) {
            return;
        }

        $this->queueHealthAlert($project, $previousStatus, $currentStatus, $issueMessage);
    }

    public function flushHealthNotifications(): void
    {
        if ($this->pendingAlerts === []) {
            return;
        }

        if (! $this->settings->isMailConfigured()) {
            $this->pendingAlerts = [];
            return;
        }

        if (! $this->settings->get('workflows.email.enabled', true)) {
            $this->pendingAlerts = [];
            return;
        }

        $grouped = [];

        foreach ($this->pendingAlerts as $alert) {
            $recipients = $this->resolveRecipients($alert['project']);
            if ($recipients === []) {
                continue;
            }

            sort($recipients);
            $key = implode('|', $recipients);
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'recipients' => $recipients,
                    'alerts' => [],
                ];
            }

            $grouped[$key]['alerts'][] = $alert;
        }

        if ($grouped === []) {
            $this->pendingAlerts = [];
            return;
        }

        try {
            $this->settings->applyMailConfig();
        } catch (\Throwable $exception) {
            $this->pendingAlerts = [];
            return;
        }

        foreach ($grouped as $group) {
            $alerts = $group['alerts'];
            if ($alerts === []) {
                continue;
            }

            $subject = count($alerts) === 1
                ? sprintf('Health check failed: %s', $alerts[0]['project']->name)
                : sprintf('Health checks failed for %d projects', count($alerts));

            $body = $this->buildBatchHealthEmailBody($alerts);

            try {
                Mail::raw($body, function ($message) use ($group, $subject) {
                    $message->to($group['recipients'])->subject($subject);
                });

                foreach ($alerts as $alert) {
                    $this->markEmailCooldown($alert['project']);
                }
            } catch (\Throwable $exception) {
                // Swallow mail errors to avoid breaking health checks.
            }
        }

        $this->pendingAlerts = [];
    }

    /**
     * @return array<int, string>
     */
    private function resolveRecipients(Project $project): array
    {
        $recipients = [];

        if ($this->settings->get('workflows.email.include_project_owner', true) && $project->user?->email) {
            $recipients[] = $project->user->email;
        }

        $extra = (string) $this->settings->get('workflows.email.recipients', '');
        if ($extra !== '') {
            $list = array_filter(array_map('trim', explode(',', $extra)));
            $recipients = array_merge($recipients, $list);
        }

        return array_values(array_unique(array_filter($recipients)));
    }

    private function buildHealthEmailBody(
        Project $project,
        string $previousStatus,
        string $currentStatus,
        ?string $issueMessage
    ): string {
        $healthUrl = $this->resolveHealthUrl($project);

        return implode("\n", array_filter([
            'Health check changed',
            'Project: '.$project->name,
            'Previous: '.strtoupper($previousStatus),
            'Current: '.strtoupper($currentStatus),
            $healthUrl ? 'Health URL: '.$healthUrl : null,
            'Checked: '.now()->toDateTimeString(),
            $issueMessage ? 'Issue: '.$issueMessage : null,
        ]));
    }

    /**
     * @param array<int, array{project: Project, previous: string, current: string, issue: string|null, url: string|null, checked_at: string}> $alerts
     */
    private function buildBatchHealthEmailBody(array $alerts): string
    {
        $lines = [
            'Health check failures detected',
            'Checked: '.now()->toDateTimeString(),
            '',
        ];

        foreach ($alerts as $index => $alert) {
            $project = $alert['project'];
            $lines[] = sprintf('%d. %s', $index + 1, $project->name);
            $lines[] = '   Previous: '.strtoupper($alert['previous']);
            $lines[] = '   Current: '.strtoupper($alert['current']);
            if ($alert['url']) {
                $lines[] = '   Health URL: '.$alert['url'];
            }
            if ($alert['issue']) {
                $lines[] = '   Issue: '.$alert['issue'];
            }
            $lines[] = '   Checked: '.$alert['checked_at'];
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    private function queueHealthAlert(
        Project $project,
        string $previousStatus,
        string $currentStatus,
        ?string $issueMessage
    ): void {
        if ($this->isEmailCooldownActive($project)) {
            return;
        }

        if (isset($this->pendingAlerts[$project->id])) {
            return;
        }

        $this->pendingAlerts[$project->id] = [
            'project' => $project,
            'previous' => $previousStatus,
            'current' => $currentStatus,
            'issue' => $issueMessage,
            'url' => $this->resolveHealthUrl($project),
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    private function isEmailCooldownActive(Project $project): bool
    {
        return Cache::has($this->emailCooldownKey($project));
    }

    private function markEmailCooldown(Project $project): void
    {
        Cache::put($this->emailCooldownKey($project), true, now()->addMinutes(self::EMAIL_COOLDOWN_MINUTES));
    }

    private function emailCooldownKey(Project $project): string
    {
        return 'gwm_health_alert_cooldown_'.$project->id;
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
