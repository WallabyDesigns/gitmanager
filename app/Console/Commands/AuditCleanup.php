<?php

namespace App\Console\Commands;

use App\Models\AuditIssue;
use App\Models\Deployment;
use Illuminate\Console\Command;

class AuditCleanup extends Command
{
    protected $signature = 'audit:cleanup {--dry-run : Show what would change without writing} {--project= : Limit to a project id}';

    protected $description = 'Resolve audit issues that are already cleared by a successful audit.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $projectId = $this->option('project');

        $query = AuditIssue::query()
            ->where('status', 'open');

        if ($projectId) {
            $query->where('project_id', (int) $projectId);
        }

        $issues = $query->orderBy('project_id')->orderBy('tool')->get();
        if ($issues->isEmpty()) {
            $this->info('No open audit issues found.');

            return self::SUCCESS;
        }

        $resolved = 0;
        foreach ($issues as $issue) {
            $tool = strtolower((string) $issue->tool);
            $deployment = $this->latestAuditDeployment($issue->project_id, $tool);
            if (! $deployment) {
                continue;
            }

            $remaining = $tool === 'composer'
                ? $this->parseComposerRemaining($deployment->output_log)
                : ($tool === 'npm' ? $this->parseNpmRemaining($deployment->output_log) : null);

            if ($remaining !== 0) {
                continue;
            }

            $resolved++;
            if ($dryRun) {
                $this->line("Would resolve audit issue #{$issue->id} for project {$issue->project_id} ({$tool}).");

                continue;
            }

            $issue->status = 'resolved';
            $issue->remaining_count = 0;
            $issue->resolved_at = now();
            $issue->last_seen_at = now();
            if (! $issue->fix_summary) {
                $issue->fix_summary = 'Audit cleanup: latest audit found no vulnerabilities.';
            }
            $issue->save();
        }

        if ($dryRun) {
            $this->info("Dry run complete. {$resolved} issue(s) would be resolved.");
        } else {
            $this->info("Cleanup complete. {$resolved} issue(s) resolved.");
        }

        return self::SUCCESS;
    }

    private function latestAuditDeployment(int $projectId, string $tool): ?Deployment
    {
        $actions = match ($tool) {
            'composer' => ['composer_audit'],
            'npm' => ['npm_audit', 'npm_audit_fix', 'npm_audit_fix_force'],
            default => [],
        };

        if ($actions === []) {
            return null;
        }

        return Deployment::query()
            ->where('project_id', $projectId)
            ->whereIn('action', $actions)
            ->where('status', '!=', 'failed')
            ->orderByDesc('started_at')
            ->first();
    }

    private function parseComposerRemaining(?string $log): ?int
    {
        $text = trim((string) $log);
        if ($text === '') {
            return null;
        }

        if (preg_match('/no security vulnerability advisories found/i', $text)) {
            return 0;
        }

        if (preg_match('/found\s+(\d+)\s+security vulnerability advisories?/i', $text, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function parseNpmRemaining(?string $log): ?int
    {
        $text = trim((string) $log);
        if ($text === '') {
            return null;
        }

        $lines = array_reverse(preg_split('/\r?\n/', $text) ?: []);
        $found = null;
        $fixed = null;
        $total = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($found === null && preg_match('/found\s+(\d+)\s+vulnerabilities(?:\s+\(([^)]+)\))?/i', $line, $matches)) {
                $found = (int) $matches[1];
            }

            if ($fixed === null && preg_match('/fixed\s+(\d+)\s+of\s+(\d+)\s+vulnerabilities/i', $line, $matches)) {
                $fixed = (int) $matches[1];
                $total = (int) $matches[2];
            }

            if ($found !== null && $fixed !== null) {
                break;
            }
        }

        if ($fixed !== null && $total !== null) {
            return max($total - $fixed, 0);
        }

        return $found;
    }
}
