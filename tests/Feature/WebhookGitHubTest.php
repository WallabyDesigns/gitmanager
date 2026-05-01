<?php

namespace Tests\Feature;

use App\Jobs\DeployProjectFromWebhook;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookGitHubTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.github.webhook_secret' => $this->secret]);
    }

    private function sign(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $this->secret);
    }

    /** @param array<string, string> $extraServer */
    private function webhook(string $payload, string $event, array $extraServer = []): TestResponse
    {
        return $this->call('POST', '/webhooks/github', [], [], [], array_merge([
            'HTTP_X_HUB_SIGNATURE_256' => $this->sign($payload),
            'HTTP_X_GITHUB_EVENT' => $event,
            'CONTENT_TYPE' => 'application/json',
        ], $extraServer), $payload);
    }

    public function test_missing_secret_config_returns_500(): void
    {
        config(['services.github.webhook_secret' => null]);

        $this->webhook('{}', 'push')->assertStatus(500);
    }

    public function test_invalid_signature_returns_401(): void
    {
        $this->call('POST', '/webhooks/github', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'CONTENT_TYPE' => 'application/json',
        ], '{}')->assertStatus(401);
    }

    public function test_non_push_event_is_ignored(): void
    {
        $payload = '{}';

        $this->webhook($payload, 'pull_request')->assertStatus(202);
    }

    public function test_push_with_no_repo_url_returns_400(): void
    {
        $payload = json_encode(['ref' => 'refs/heads/main', 'repository' => []]);

        $this->webhook($payload, 'push')->assertStatus(400);
    }

    public function test_push_for_unknown_project_returns_404(): void
    {
        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['html_url' => 'https://github.com/org/unknown.git'],
        ]);

        $this->webhook($payload, 'push')->assertStatus(404);
    }

    public function test_push_on_non_default_branch_is_ignored(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/org/repo.git',
            'default_branch' => 'main',
            'auto_deploy' => true,
        ]);

        $payload = json_encode([
            'ref' => 'refs/heads/develop',
            'repository' => ['html_url' => $project->repo_url],
        ]);

        $this->webhook($payload, 'push')->assertStatus(202);
    }

    public function test_push_with_auto_deploy_disabled_is_ignored(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/org/repo.git',
            'default_branch' => 'main',
            'auto_deploy' => false,
        ]);

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['html_url' => $project->repo_url],
        ]);

        $this->webhook($payload, 'push')->assertStatus(202);
    }

    public function test_valid_push_queues_deploy_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/org/repo.git',
            'default_branch' => 'main',
            'auto_deploy' => true,
        ]);

        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['html_url' => $project->repo_url],
        ]);

        $this->webhook($payload, 'push')->assertStatus(202);

        Queue::assertPushed(DeployProjectFromWebhook::class, fn ($job) => $job->projectId === $project->id);
    }
}
