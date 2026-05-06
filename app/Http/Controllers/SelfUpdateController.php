<?php

namespace App\Http\Controllers;

use App\Models\AppUpdate;
use App\Services\SelfUpdateService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class SelfUpdateController extends Controller
{
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

        if ($update) {
            $lines = array_merge($lines, [
                '',
                'Update status: '.$update->status,
                'Started: '.($update->started_at?->toDateTimeString() ?? 'pending'),
                'Finished: '.($update->finished_at?->toDateTimeString() ?? 'running'),
                'From: '.($update->from_hash ?? 'pending'),
                'To: '.($update->to_hash ?? 'pending'),
                '',
                'Log:',
                $update->output_log ?: 'Waiting for update output...',
            ]);
        } else {
            $lines = array_merge($lines, [
                '',
                'Update status: starting',
                '',
                'Log:',
                'Waiting for the background updater to create its log entry...',
            ]);
        }

        $response = response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');

        if ($launchStarted && (! $update || $update->status === 'running')) {
            $response->header('Refresh', '2');
        }

        return $response;
    }

    public function rollback(SelfUpdateService $service): Response
    {
        $target = trim((string) Request::query('hash', ''));
        $update = $service->rollback(Auth::user(), $target !== '' ? $target : null);

        return $this->plainResponse('Rollback', $update);
    }

    private function plainResponse(string $label, AppUpdate $update): Response
    {
        $lines = [
            $label.' status: '.$update->status,
            'Started: '.($update->started_at?->toDateTimeString() ?? '—'),
            'Finished: '.($update->finished_at?->toDateTimeString() ?? '—'),
            'From: '.($update->from_hash ?? '—'),
            'To: '.($update->to_hash ?? '—'),
            '',
            'Log:',
            $update->output_log ?: 'No output captured.',
        ];

        return response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
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
