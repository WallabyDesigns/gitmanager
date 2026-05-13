<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

class AppSelfAudit extends Command
{
    protected $signature = 'app:self-audit';

    protected $description = 'Audit this Git Web Manager installation dependencies.';

    public function handle(SettingsService $settings): int
    {
        $startedAt = now();

        $composer = $this->runComposerAudit();
        $summary = $this->summarize($composer);

        $this->storeAuditState($settings, $startedAt, $composer, $summary);

        $this->line($summary['message']);

        if ($composer['status'] === 'failed') {
            $this->warn('Composer audit failed: '.$composer['message']);
        } elseif ($composer['status'] !== 'skipped') {
            $this->info('Composer advisories: '.$composer['remaining']);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{status: string, remaining: int, message: string, output: string}
     */
    private function runComposerAudit(): array
    {
        $composerJson = base_path('composer.json');
        if (! is_file($composerJson)) {
            return [
                'status' => 'skipped',
                'remaining' => 0,
                'message' => 'composer.json not found.',
                'output' => '',
            ];
        }

        $result = $this->runProcess([
            'composer',
            'audit',
            '--locked',
            '--format=json',
            '--no-interaction',
        ], 300);

        if (! $result['ran']) {
            return [
                'status' => 'failed',
                'remaining' => 0,
                'message' => $result['message'],
                'output' => $result['output'],
            ];
        }

        $decoded = json_decode($result['output'], true);
        if (! is_array($decoded)) {
            return [
                'status' => 'failed',
                'remaining' => 0,
                'message' => 'Composer audit returned invalid JSON.',
                'output' => $result['output'],
            ];
        }

        $remaining = 0;
        $advisories = $decoded['advisories'] ?? [];
        if (is_array($advisories)) {
            foreach ($advisories as $packageAdvisories) {
                if (is_array($packageAdvisories)) {
                    $remaining += count($packageAdvisories);
                }
            }
        }

        return [
            'status' => $remaining > 0 ? 'warning' : 'ok',
            'remaining' => $remaining,
            'message' => $remaining > 0
                ? "Composer reported {$remaining} advisories."
                : 'Composer reported no advisories.',
            'output' => $result['output'],
        ];
    }

    /**
     * @return array{status: string, remaining: int, message: string}
     */
    private function summarize(array $composer): array
    {
        $remaining = (int) ($composer['remaining'] ?? 0);
        $hasFailure = (string) ($composer['status'] ?? '') === 'failed';

        if ($hasFailure) {
            return [
                'status' => 'failed',
                'remaining' => $remaining,
                'message' => 'Self-audit completed with errors.',
            ];
        }

        if ($remaining > 0) {
            return [
                'status' => 'warning',
                'remaining' => $remaining,
                'message' => "Self-audit detected {$remaining} vulnerability issue(s).",
            ];
        }

        return [
            'status' => 'ok',
            'remaining' => 0,
            'message' => 'Self-audit passed with no detected vulnerabilities.',
        ];
    }

    private function storeAuditState(SettingsService $settings, Carbon $startedAt, array $composer, array $summary): void
    {
        try {
            $settings->set('system.self_audit_last_run_at', $startedAt->toIso8601String());
            $settings->set('system.self_audit_status', (string) ($summary['status'] ?? 'unknown'));
            $settings->set('system.self_audit_remaining', (int) ($summary['remaining'] ?? 0));
            $settings->set('system.self_audit_summary', (string) ($summary['message'] ?? 'Self-audit completed.'));
            $settings->set('system.self_audit_composer_remaining', (int) ($composer['remaining'] ?? 0));
            $settings->set('system.self_audit_composer_status', (string) ($composer['status'] ?? 'unknown'));
            $settings->set('system.self_audit_details', [
                'composer' => [
                    'status' => (string) ($composer['status'] ?? 'unknown'),
                    'remaining' => (int) ($composer['remaining'] ?? 0),
                    'message' => (string) ($composer['message'] ?? ''),
                ],
            ]);
        } catch (\Throwable $exception) {
            // Ignore settings persistence issues during installs or migrations.
        }
    }

    /**
     * @param  array<int, string>  $command
     * @return array{ran: bool, message: string, output: string, exit_code: int|null}
     */
    private function runProcess(array $command, int $timeout): array
    {
        try {
            $process = new Process($command, base_path());
            $process->setTimeout($timeout > 0 ? $timeout : null);
            $process->run();

            $output = trim($process->getOutput()."\n".$process->getErrorOutput());
            $exitCode = $process->getExitCode();
            $binaryMissing = in_array($exitCode, [126, 127, 9009], true)
                || str_contains(strtolower($output), 'not found')
                || str_contains(strtolower($output), 'is not recognized');

            return [
                'ran' => ! $binaryMissing,
                'message' => $binaryMissing ? 'Required binary is not installed on this host.' : 'Command completed.',
                'output' => $output,
                'exit_code' => $exitCode,
            ];
        } catch (\Throwable $exception) {
            return [
                'ran' => false,
                'message' => $exception->getMessage(),
                'output' => '',
                'exit_code' => null,
            ];
        }
    }
}
