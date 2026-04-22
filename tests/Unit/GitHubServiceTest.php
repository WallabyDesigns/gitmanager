<?php

namespace Tests\Unit;

use App\Services\GitHubService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubServiceTest extends TestCase
{
    public function test_get_latest_deployment_status_for_ref_reads_latest_status(): void
    {
        Http::fake([
            'https://api.github.com/repos/wallabydesigns/gitmanager/deployments/42/statuses*' => Http::response([
                [
                    'state' => 'failure',
                    'environment' => 'production',
                    'description' => 'Smoke tests failed.',
                    'created_at' => '2026-04-22T01:00:00Z',
                    'updated_at' => '2026-04-22T01:05:00Z',
                    'target_url' => 'https://github.com/wallabydesigns/gitmanager/deployments/42',
                    'log_url' => 'https://github.com/wallabydesigns/gitmanager/actions/runs/42',
                ],
            ], 200),
            'https://api.github.com/repos/wallabydesigns/gitmanager/deployments*' => Http::response([
                [
                    'id' => 42,
                    'environment' => 'production',
                ],
            ], 200),
        ]);

        $status = app(GitHubService::class)->getLatestDeploymentStatusForRef(
            'wallabydesigns/gitmanager',
            'abc1234',
            'production'
        );

        $this->assertNotNull($status);
        $this->assertSame(42, $status['deployment_id']);
        $this->assertSame('failure', $status['state']);
        $this->assertSame('production', $status['environment']);
        $this->assertSame('Smoke tests failed.', $status['description']);
    }
}
