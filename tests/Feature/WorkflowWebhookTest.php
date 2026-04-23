<?php

namespace Tests\Feature;

use App\Livewire\Workflows\Index as WorkflowIndex;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_form_saves_multiple_actions_and_destinations(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(WorkflowIndex::class)
            ->set('name', 'Release Notifications')
            ->set('selectedActions', ['deploy', 'rollback'])
            ->set('selectedStatuses', ['success', 'failed'])
            ->set('deliveries', [
                [
                    'id' => 'email-destination',
                    'type' => 'email',
                    'name' => 'Ops Team',
                    'include_owner' => true,
                    'recipients' => "ops@example.com\nalerts@example.com",
                    'url' => '',
                    'secret' => '',
                    'has_secret' => false,
                ],
                [
                    'id' => 'webhook-destination',
                    'type' => 'webhook',
                    'name' => 'Release API',
                    'include_owner' => false,
                    'recipients' => '',
                    'url' => 'https://example.com/workflows/release',
                    'secret' => 'topsecret',
                    'has_secret' => false,
                ],
            ])
            ->call('saveWorkflow')
            ->assertHasNoErrors();

        $workflow = Workflow::query()->sole();

        $this->assertSame(['deploy', 'rollback'], $workflow->triggerActions());
        $this->assertSame(['success', 'failed'], $workflow->triggerStatuses());
        $this->assertSame('multi', $workflow->channel);
        $this->assertCount(2, $workflow->deliveryDefinitions());
        $this->assertSame('https://example.com/workflows/release', $workflow->deliveryDefinitions()[1]['url'] ?? null);
        $this->assertIsString($workflow->deliveryDefinitions()[1]['secret_encrypted'] ?? null);
    }

    public function test_handle_deployment_fans_out_to_email_and_webhook_for_multi_destination_workflow(): void
    {
        Http::fake([
            'https://example.com/workflows/release' => Http::response(['ok' => true], 200),
        ]);
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);

        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name' => 'Release Portal',
            'repo_url' => 'https://github.com/example/release-portal.git',
            'default_branch' => 'main',
            'local_path' => '/var/www/release-portal',
        ]);

        $deployment = Deployment::query()->create([
            'project_id' => $project->id,
            'triggered_by' => $user->id,
            'action' => 'deploy',
            'status' => 'success',
            'from_hash' => 'abc123',
            'to_hash' => 'def456',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $workflow = Workflow::query()->create([
            'name' => 'Release Notifications',
            'enabled' => true,
            'action' => 'deploy',
            'status' => 'success',
            'channel' => 'multi',
            'include_owner' => true,
            'recipients' => 'ops@example.com',
            'trigger_actions' => ['deploy'],
            'trigger_statuses' => ['success'],
            'deliveries' => [
                [
                    'id' => 'email-destination',
                    'type' => 'email',
                    'name' => 'Ops Team',
                    'include_owner' => true,
                    'recipients' => 'ops@example.com',
                ],
                [
                    'id' => 'webhook-destination',
                    'type' => 'webhook',
                    'name' => 'Release API',
                    'url' => 'https://example.com/workflows/release',
                    'secret_encrypted' => Crypt::encryptString('topsecret'),
                ],
            ],
        ]);

        $messages = app(WorkflowService::class)->handleDeployment($deployment, $project);

        Http::assertSent(function ($request) use ($workflow, $project) {
            $data = $request->data();
            $expectedSignature = hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_SLASHES), 'topsecret');

            return $request->url() === 'https://example.com/workflows/release'
                && $request->hasHeader('X-GWM-Signature', $expectedSignature)
                && ($data['event_standard'] ?? null) === 'deploy.success'
                && ($data['workflow']['name'] ?? null) === $workflow->name
                && ($data['workflow']['actions'] ?? null) === ['deploy']
                && ($data['links']['project'] ?? null) === route('projects.show', $project)
                && ($data['destination']['target'] ?? null) === 'https://example.com/workflows/release';
        });

        $this->assertTrue(collect($messages)->contains(fn ($message) => str_contains($message, 'email')));
        $this->assertTrue(collect($messages)->contains(fn ($message) => str_contains($message, 'webhook')));
    }

    public function test_send_test_webhook_uses_selected_event_preview_and_signature(): void
    {
        Http::fake([
            'https://example.com/workflow' => Http::response(['ok' => true], 200),
        ]);

        $admin = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(WorkflowIndex::class)
            ->set('name', 'Deploy Success Webhook')
            ->set('selectedActions', ['deploy'])
            ->set('selectedStatuses', ['success'])
            ->set('deliveries', [
                [
                    'id' => 'webhook-destination',
                    'type' => 'webhook',
                    'name' => 'Release API',
                    'include_owner' => false,
                    'recipients' => '',
                    'url' => 'https://example.com/workflow',
                    'secret' => 'topsecret',
                    'has_secret' => false,
                ],
            ])
            ->call('sendTestWebhook');

        Http::assertSent(function ($request) {
            $data = $request->data();
            $expectedSignature = hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_SLASHES), 'topsecret');

            return $request->url() === 'https://example.com/workflow'
                && $request->hasHeader('X-GWM-Signature', $expectedSignature)
                && ($data['event'] ?? null) === 'workflow.test'
                && ($data['workflow']['actions'] ?? null) === ['deploy']
                && ($data['workflow']['statuses'] ?? null) === ['success'];
        });
    }
}
