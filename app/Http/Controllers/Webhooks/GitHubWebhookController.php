<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\DeployProjectFromWebhook;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $secret = config('services.github.webhook_secret');
        if (! $secret) {
            return response('Webhook secret not configured.', 500);
        }

        $signature = $request->header('X-Hub-Signature-256');
        if (! $this->isValidSignature($signature, $secret, $request->getContent())) {
            return response('Invalid signature.', 401);
        }

        $event = $request->header('X-GitHub-Event');
        if ($event !== 'push') {
            return response('Event ignored.', 202);
        }

        $payload = $request->json()->all();
        $repository = $payload['repository'] ?? [];
        $repoUrls = array_values(array_filter([
            $repository['html_url'] ?? null,
            $repository['ssh_url'] ?? null,
            $repository['clone_url'] ?? null,
        ]));

        if (! $repoUrls) {
            return response('Repository URL missing.', 400);
        }

        $project = Project::query()
            ->whereIn('repo_url', $repoUrls)
            ->first();

        if (! $project) {
            return response('Project not found.', 404);
        }

        $ref = $payload['ref'] ?? '';
        $branch = str_replace('refs/heads/', '', $ref);
        if ($branch !== $project->default_branch) {
            return response('Branch ignored.', 202);
        }

        if (! $project->auto_deploy) {
            return response('Auto deploy disabled.', 202);
        }

        DeployProjectFromWebhook::dispatch($project->id);

        return response('Queued.', 202);
    }

    private function isValidSignature(?string $signature, string $secret, string $payload): bool
    {
        if (! $signature || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
