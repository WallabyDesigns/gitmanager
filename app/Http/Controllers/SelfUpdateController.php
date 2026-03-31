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
        $update = $service->updateSmart(Auth::user());

        return $this->plainResponse('Update', $update);
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
}
