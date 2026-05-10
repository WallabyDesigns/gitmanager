<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

class HealthCheckService
{
    private const EMAIL_COOLDOWN_MINUTES = 10;
    private const HTTP_ATTEMPTS = 3;
    private const TRANSPORT_FAILURE_THRESHOLD = 3;

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
        $healthUrl = $this->resolveHealthUrl($project);
        $httpStatus = null;
        $laravelIssue = $this->runLaravelHealthChecks($project, $healthLog);

        if ($laravelIssue !== null) {
            $healthLog[] = 'Laravel checks failed: '.$laravelIssue;

            $enforceLaravelChecks = ! $project->ftp_enabled && ! $project->ssh_enabled;
            if ($enforceLaravelChecks || ! $healthUrl) {
                $status = 'na';
                $issueMessage = $laravelIssue;
                $logText = $this->formatHealthLog($healthLog);
                $this->saveHealthStatus($project, $status, $issueMessage, $logText, $healthUrl, $httpStatus);
                $this->maybeNotifyHealthChange($project, $previousStatus, $status, $issueMessage, $notifyOnFailure);

                return $status;
            }
        }

        if (! $healthUrl) {
            $this->saveHealthStatus($project, 'na', 'No health URL configured.', $this->formatHealthLog($healthLog), null, null);

            return 'na';
        }

        $transportException = null;
        $skipPrimaryTransport = false;
        if ($this->shouldUseFallbackFirst($project, $healthUrl)) {
            $fallbackStatus = $this->fallbackHttpStatus($healthUrl);
            if ($fallbackStatus !== null) {
                $httpStatus = $fallbackStatus;
                $status = $fallbackStatus >= 200 && $fallbackStatus < 400 ? 'ok' : 'na';
                $healthLog[] = sprintf(
                    'HTTP health check %s via fallback transport after repeated primary transport failures (%d).',
                    $status === 'ok' ? 'OK' : 'failed',
                    $fallbackStatus
                );
                Cache::forget($this->consecutiveFailKey($project));
            } else {
                $healthLog[] = 'Primary HTTP transport is cooling down, but fallback transport was unavailable.';
                $transportException = new \RuntimeException('Primary HTTP transport is cooling down and fallback transport was unavailable.');
                $skipPrimaryTransport = true;
            }
        }

        for ($attempt = 1; ! $skipPrimaryTransport && ! isset($status) && $attempt <= self::HTTP_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(10)->get($healthUrl);
                $httpStatus = $response->status();
                if ($response->successful()) {
                    $healthLog[] = 'HTTP health check OK ('.$response->status().').';
                    Cache::forget($this->consecutiveFailKey($project));
                    $this->clearPrimaryTransportFailures($project, $healthUrl);
                    $status = 'ok';
                } else {
                    $healthLog[] = 'HTTP health check failed ('.$response->status().').';
                    Cache::forget($this->consecutiveFailKey($project));
                    $this->clearPrimaryTransportFailures($project, $healthUrl);
                    $status = 'na';
                }
                $transportException = null;

                break;
            } catch (\Throwable $exception) {
                if ($this->isInconclusiveTransportException($exception) && $this->fallbackEnabled()) {
                    $this->recordPrimaryTransportFailure($project, $healthUrl);
                    $fallbackStatus = $this->fallbackHttpStatus($healthUrl);
                    if ($fallbackStatus !== null) {
                        $httpStatus = $fallbackStatus;
                        if ($fallbackStatus >= 200 && $fallbackStatus < 400) {
                            $healthLog[] = 'HTTP health check OK via fallback transport ('.$fallbackStatus.').';
                            Cache::forget($this->consecutiveFailKey($project));
                            $status = 'ok';
                        } else {
                            $healthLog[] = 'HTTP health check failed via fallback transport ('.$fallbackStatus.').';
                            Cache::forget($this->consecutiveFailKey($project));
                            $status = 'na';
                        }
                        $transportException = null;

                        break;
                    }
                }

                $transportException = $exception;
                $healthLog[] = sprintf(
                    'HTTP health check transport exception (attempt %d/%d): %s',
                    $attempt,
                    self::HTTP_ATTEMPTS,
                    $this->cleanExceptionMessage($exception)
                );
            }
        }

        if ($transportException !== null) {
            if ($this->isInconclusiveTransportException($transportException)) {
                $issueMessage = $this->transportIssueMessage($transportException);
                $projectIssueMessage = ($previousStatus ?? 'na') === 'ok' ? null : $previousIssue;
                $healthLog[] = 'Transport failure is local/inconclusive; preserving previous health status.';
                $logText = $this->formatHealthLog($healthLog);
                Cache::forget($this->consecutiveFailKey($project));
                $this->saveHealthStatus(
                    $project,
                    $previousStatus ?? 'na',
                    $projectIssueMessage,
                    $logText,
                    $healthUrl,
                    $httpStatus,
                    'inconclusive',
                    $issueMessage,
                    'inconclusive'
                );

                return $previousStatus ?? 'na';
            }

            $failCount = (int) Cache::get($this->consecutiveFailKey($project), 0) + 1;
            Cache::put($this->consecutiveFailKey($project), $failCount, now()->addHours(2));
            $issueMessage = $this->transportIssueMessage($transportException);
            if ($failCount < self::TRANSPORT_FAILURE_THRESHOLD) {
                $healthLog[] = sprintf(
                    'Transport failure %d/%d; preserving previous health status until the failure threshold is reached.',
                    $failCount,
                    self::TRANSPORT_FAILURE_THRESHOLD
                );
                $logText = $this->formatHealthLog($healthLog);
                $this->saveHealthStatus($project, $previousStatus ?? 'na', $previousIssue, $logText, $healthUrl, $httpStatus, 'na', $issueMessage);

                return $previousStatus ?? 'na';
            }
            $status = 'na';
        } else {
            $issueMessage = $status === 'ok' ? null : ($httpStatus !== null ? "HTTP {$httpStatus} failed." : null);
        }

        $logText = $this->formatHealthLog($healthLog);
        $this->saveHealthStatus($project, $status, $issueMessage, $logText, $healthUrl, $httpStatus);
        $this->maybeNotifyHealthChange($project, $previousStatus, $status, $issueMessage, $notifyOnFailure);

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

    private function saveHealthStatus(Project $project, string $status, ?string $issueMessage, ?string $logText, ?string $healthUrl, ?int $httpStatus, ?string $historyStatus = null, ?string $historyIssueMessage = null, ?string $historyDeploymentStatus = null): void
    {
        $project->health_status = $status;
        $project->health_issue_message = $issueMessage;
        $project->health_log = $this->appendHealthHistory($project, $historyStatus ?? $status, $historyIssueMessage ?? $issueMessage, $logText, $healthUrl, $httpStatus, $historyDeploymentStatus);
        $project->health_checked_at = now();
        $project->save();
    }

    private function appendHealthHistory(Project $project, string $status, ?string $issueMessage, ?string $logText, ?string $healthUrl, ?int $httpStatus, ?string $deploymentStatus = null): string
    {
        $history = $project->healthHistory();

        $history[] = [
            'checked_at' => now()->toIso8601String(),
            'status' => $status,
            'deployment_status' => $deploymentStatus ?? ($status === 'ok' ? 'success' : 'failed'),
            'http_status' => $httpStatus,
            'issue' => $issueMessage,
            'url' => $healthUrl,
            'summary' => $this->healthSummary($status, $issueMessage, $logText, $httpStatus),
        ];

        if (count($history) > Project::HEALTH_HISTORY_LIMIT) {
            $history = array_slice($history, -Project::HEALTH_HISTORY_LIMIT);
        }

        return json_encode([
            'version' => 1,
            'checks' => array_values($history),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function healthSummary(string $status, ?string $issueMessage, ?string $logText, ?int $httpStatus): string
    {
        if ($status === 'inconclusive') {
            return 'Health check inconclusive: local HTTP transport could not complete.';
        }

        if ($httpStatus !== null) {
            return $status === 'ok'
                ? "HTTP {$httpStatus}"
                : "HTTP {$httpStatus} failed";
        }

        if ($issueMessage) {
            return $issueMessage;
        }

        $lastLine = collect(explode("\n", (string) $logText))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->last();

        return is_string($lastLine) && $lastLine !== '' ? $lastLine : 'Health check completed.';
    }

    private function transportIssueMessage(\Throwable $exception): string
    {
        return 'HTTP transport failed: '.$this->cleanExceptionMessage($exception);
    }

    private function isInconclusiveTransportException(\Throwable $exception): bool
    {
        $message = strtolower($this->cleanExceptionMessage($exception));

        return str_contains($message, 'getaddrinfo() thread failed to start')
            || str_contains($message, 'getaddrinfo thread failed to start');
    }

    private function fallbackEnabled(): bool
    {
        return (bool) config('gitmanager.health.stream_fallback_enabled', true)
            || (bool) config('gitmanager.health.cli_fallback_enabled', true);
    }

    private function shouldUseFallbackFirst(Project $project, string $url): bool
    {
        return $this->fallbackEnabled() && Cache::has($this->primaryTransportCooldownKey($project, $url));
    }

    private function recordPrimaryTransportFailure(Project $project, string $url): void
    {
        $threshold = max(1, (int) config('gitmanager.health.primary_failure_threshold', 3));
        $failureKey = $this->primaryTransportFailureKey($project, $url);
        $failures = (int) Cache::get($failureKey, 0) + 1;

        Cache::put($failureKey, $failures, now()->addHour());

        if ($failures >= $threshold) {
            Cache::put(
                $this->primaryTransportCooldownKey($project, $url),
                true,
                now()->addSeconds(max(60, (int) config('gitmanager.health.primary_fallback_seconds', 3600)))
            );
        }
    }

    private function clearPrimaryTransportFailures(Project $project, string $url): void
    {
        Cache::forget($this->primaryTransportFailureKey($project, $url));
        Cache::forget($this->primaryTransportCooldownKey($project, $url));
    }

    protected function fallbackHttpStatus(string $url): ?int
    {
        $streamStatus = (bool) config('gitmanager.health.stream_fallback_enabled', true)
            ? $this->streamHttpStatus($url)
            : null;

        return $streamStatus ?? $this->cliHttpStatus($url);
    }

    protected function streamHttpStatus(string $url): ?int
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'User-Agent: Git Web Manager Health Check',
                    'Accept: */*',
                ]),
            ],
        ]);

        $headers = [];
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            $result = file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];
        } finally {
            restore_error_handler();
        }

        if ($result === false && $headers === []) {
            return null;
        }

        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string) $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    protected function cliHttpStatus(string $url): ?int
    {
        if (! (bool) config('gitmanager.health.cli_fallback_enabled', true)) {
            return null;
        }

        return $this->bundledProbeStatus($url) ?? $this->httpieStatus($url) ?? $this->curlStatus($url);
    }

    private function bundledProbeStatus(string $url): ?int
    {
        if (! (bool) config('gitmanager.health.bundled_probe_enabled', true)) {
            return null;
        }

        $script = (string) config('gitmanager.health.bundled_probe_script', base_path('bin/gwm-http-status.php'));
        if ($script === '' || ! is_file($script)) {
            return null;
        }

        $phpBinary = trim((string) config('gitmanager.php_binary', PHP_BINARY ?: 'php'));
        if ($phpBinary === '') {
            $phpBinary = PHP_BINARY ?: 'php';
        }

        return $this->runStatusCommand([
            $phpBinary,
            $script,
            $url,
            '10',
        ]);
    }

    private function httpieStatus(string $url): ?int
    {
        $binary = trim((string) config('gitmanager.health.httpie_binary', 'http'));
        if ($binary === '') {
            return null;
        }

        return $this->runStatusCommand([
            $binary,
            '--ignore-stdin',
            '--headers',
            '--timeout=10',
            'GET',
            $url,
        ]);
    }

    private function curlStatus(string $url): ?int
    {
        $binary = trim((string) config('gitmanager.health.curl_binary', 'curl'));
        if ($binary === '') {
            return null;
        }

        return $this->runStatusCommand([
            $binary,
            '--head',
            '--location',
            '--max-time',
            '10',
            '--silent',
            '--show-error',
            $url,
        ]);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runStatusCommand(array $command): ?int
    {
        try {
            $process = new Process($command);
            $process->setTimeout(15);
            $process->run();
        } catch (\Throwable $exception) {
            return null;
        }

        return $this->parseHttpStatus($process->getOutput()."\n".$process->getErrorOutput());
    }

    private function parseHttpStatus(string $output): ?int
    {
        $status = null;
        if (preg_match_all('/^HTTP\/\S+\s+(\d{3})\b/im', $output, $matches)) {
            foreach ($matches[1] as $match) {
                $status = (int) $match;
            }
        }

        return $status;
    }

    private function cleanExceptionMessage(\Throwable $exception): string
    {
        return trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?: $exception->getMessage());
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

        if (! $this->settings->isMailConfigured() || ! $this->settings->get('workflows.email.enabled', true)) {
            $this->pendingAlerts = [];

            return;
        }

        $grouped = $this->groupAlertsByRecipients();

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
            $this->sendAlertGroup($group);
        }

        $this->pendingAlerts = [];
    }

    /**
     * @return array<string, array{recipients: array<int, string>, alerts: list<array{project: Project, previous: string, current: string, issue: string|null, url: string|null, checked_at: string}>}>
     */
    private function groupAlertsByRecipients(): array
    {
        $grouped = [];

        foreach ($this->pendingAlerts as $alert) {
            $recipients = $this->resolveRecipients($alert['project']);
            if ($recipients === []) {
                continue;
            }

            sort($recipients);
            $key = implode('|', $recipients);
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['recipients' => $recipients, 'alerts' => []];
            }

            $grouped[$key]['alerts'][] = $alert;
        }

        return $grouped;
    }

    /**
     * @param  array{recipients: array<int, string>, alerts: list<array{project: Project, previous: string, current: string, issue: string|null, url: string|null, checked_at: string}>}  $group
     */
    private function sendAlertGroup(array $group): void
    {
        $alerts = $group['alerts'];
        if ($alerts === []) {
            return;
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

    /**
     * @param  array<int, array{project: Project, previous: string, current: string, issue: string|null, url: string|null, checked_at: string}>  $alerts
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
        if (Cache::has($this->emailCooldownKey($project))) {
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

    private function markEmailCooldown(Project $project): void
    {
        Cache::put($this->emailCooldownKey($project), true, now()->addMinutes(self::EMAIL_COOLDOWN_MINUTES));
    }

    private function emailCooldownKey(Project $project): string
    {
        return 'gwm_health_alert_cooldown_'.$project->id;
    }

    private function consecutiveFailKey(Project $project): string
    {
        return 'gwm_health_consecutive_fail_'.$project->id;
    }

    private function primaryTransportFailureKey(Project $project, string $url): string
    {
        return 'gwm_health_primary_transport_fail_'.$project->id.'_'.sha1($url);
    }

    private function primaryTransportCooldownKey(Project $project, string $url): string
    {
        return 'gwm_health_primary_transport_cooldown_'.$project->id.'_'.sha1($url);
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
