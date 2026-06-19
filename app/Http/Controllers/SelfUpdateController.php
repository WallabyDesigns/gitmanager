<?php

namespace App\Http\Controllers;

use App\Models\AppUpdate;
use App\Services\SelfUpdateService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class SelfUpdateController extends Controller
{
    private const CONTENT_TYPE = 'text/plain; charset=UTF-8';

    public function update(SelfUpdateService $service): Response
    {
        $running = $this->runningManualUpdate();
        $launchWindowStartedAt = now()->subSeconds(2);
        $result = $running
            ? ['ok' => true, 'message' => 'A self-update is already running.']
            : $service->startUpdateInBackground(Auth::user());
        $launchStarted = (bool) ($result['ok'] ?? false);
        $update = $running ?: (
            $launchStarted
                ? $this->latestManualUpdateSince($launchWindowStartedAt)
                : $this->latestManualUpdate()
        );

        $lines = [
            'Update launch: '.(($result['ok'] ?? false) ? 'started' : 'failed'),
            $result['message'] ?? '',
        ];

        $lines = array_merge($lines, $update
            ? $this->updateLines($update, 'Update status')
            : ['', 'Update status: starting', '', 'Log:', 'Waiting for the background updater to create its log entry...']
        );

        $response = $this->plainText(implode("\n", $lines));

        if ($launchStarted && (! $update || $update->status === 'running')) {
            $response->header('Refresh', '2');
        }

        return $response;
    }

    public function status(): Response
    {
        $update = $this->latestManualUpdate();

        if (! $update) {
            return $this->plainText("No update record found.\n");
        }

        return $this->plainText(implode("\n", $this->updateLines($update, 'Status')));
    }

    public function rollback(SelfUpdateService $service): Response
    {
        $target = trim((string) Request::query('hash', ''));
        $update = $service->rollback(Auth::user(), $target !== '' ? $target : null);

        return $this->plainText(implode("\n", $this->updateLines($update, 'Rollback status', '—', 'No output captured.')));
    }

    private function updateLines(AppUpdate $update, string $statusLabel, string $missingPlaceholder = 'pending', string $noLogMessage = 'Waiting for update output...'): array
    {
        return [
            '',
            $statusLabel.': '.$update->status,
            'Started: '.($update->started_at?->toDateTimeString() ?? $missingPlaceholder),
            'Finished: '.($update->finished_at?->toDateTimeString() ?? ($missingPlaceholder === 'pending' ? 'running' : $missingPlaceholder)),
            'From: '.($update->from_hash ?? $missingPlaceholder),
            'To: '.($update->to_hash ?? $missingPlaceholder),
            '',
            'Log:',
            $update->output_log ?: $noLogMessage,
        ];
    }

    private function plainText(string $content): Response
    {
        return response($content, 200)->header('Content-Type', self::CONTENT_TYPE);
    }

    private function latestManualUpdate(): ?AppUpdate
    {
        return AppUpdate::query()
            ->whereIn('action', ['self_update', 'force_update'])
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    private function latestManualUpdateSince(\DateTimeInterface $startedAt): ?AppUpdate
    {
        return AppUpdate::query()
            ->whereIn('action', ['self_update', 'force_update'])
            ->where('started_at', '>=', $startedAt)
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    private function runningManualUpdate(): ?AppUpdate
    {
        return AppUpdate::query()
            ->whereIn('action', ['self_update', 'force_update'])
            ->where('status', 'running')
            ->latest('started_at')
            ->latest('id')
            ->first();
    }
}
