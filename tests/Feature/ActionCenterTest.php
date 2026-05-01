<?php

namespace Tests\Feature;

use App\Livewire\Security\Index as SecurityIndex;
use App\Models\AuditIssue;
use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Models\SecurityAlert;
use App\Models\User;
use App\Services\DeploymentService;
use App\Services\EditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActionCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_action_center_loads_for_authenticated_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('projects.action-center'))
            ->assertOk()
            ->assertSee('Action Center')
            ->assertSee('Current Issues')
            ->assertDontSee('Resolved Issues');
    }

    public function test_projects_action_center_shows_resolution_buttons_for_current_issues(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
        ]);

        SecurityAlert::query()->create([
            'project_id' => $project->id,
            'github_alert_id' => 1001,
            'state' => 'open',
            'severity' => 'high',
            'package_name' => 'laravel/framework',
            'ecosystem' => 'composer',
        ]);

        $this->actingAs($user)
            ->get(route('projects.action-center'))
            ->assertOk()
            ->assertSee('Attempt Resolve All')
            ->assertSee('Attempt Resolve All (Force)')
            ->assertSee('Attempt Fix')
            ->assertSee('Attempt Fix (Force)');
    }

    public function test_resolving_a_security_alert_queues_audit_and_update_actions_when_other_tasks_are_active(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);

        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'permissions_locked' => false,
            'ftp_enabled' => false,
            'ssh_enabled' => false,
        ]);

        $alert = SecurityAlert::query()->create([
            'project_id' => $project->id,
            'github_alert_id' => 2001,
            'state' => 'open',
            'severity' => 'high',
            'package_name' => 'laravel/framework',
            'ecosystem' => 'composer',
        ]);

        Deployment::query()->create([
            'project_id' => $project->id,
            'triggered_by' => $user->id,
            'action' => 'deploy',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->mockEnterpriseEdition();

        Livewire::actingAs($user)
            ->test(SecurityIndex::class)
            ->call('resolveSecurityAlert', $alert->id);

        $actions = DeploymentQueueItem::query()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->pluck('action')
            ->all();

        $this->assertSame(['audit_project', 'composer_update'], $actions);
    }

    public function test_resolving_a_security_alert_queues_when_queue_is_idle(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);

        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'permissions_locked' => false,
            'ftp_enabled' => false,
            'ssh_enabled' => false,
        ]);

        $alert = SecurityAlert::query()->create([
            'project_id' => $project->id,
            'github_alert_id' => 2002,
            'state' => 'open',
            'severity' => 'high',
            'package_name' => 'laravel/framework',
            'ecosystem' => null,
            'manifest_path' => null,
        ]);

        $this->mockEnterpriseEdition();

        $this->mock(DeploymentService::class, function ($mock) use ($project): void {
            $mock->shouldReceive('hasComposer')
                ->once()
                ->withArgs(fn (Project $candidate): bool => $candidate->is($project))
                ->andReturn(true);
            $mock->shouldReceive('hasNpm')
                ->once()
                ->withArgs(fn (Project $candidate): bool => $candidate->is($project))
                ->andReturn(false);
            $mock->shouldReceive('releaseStaleRunningDeployments')
                ->once()
                ->andReturnNull();
            $mock->shouldNotReceive('composerUpdate');
        });

        Livewire::actingAs($user)
            ->test(SecurityIndex::class)
            ->call('resolveSecurityAlert', $alert->id);

        $items = DeploymentQueueItem::query()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->get(['action', 'status'])
            ->map(fn (DeploymentQueueItem $item): array => [
                'action' => $item->action,
                'status' => $item->status,
            ])
            ->all();

        $this->assertSame([
            ['action' => 'audit_project', 'status' => 'queued'],
            ['action' => 'composer_update', 'status' => 'queued'],
        ], $items);
    }

    public function test_force_fix_for_npm_audit_issue_queues_force_npm_action_when_other_tasks_are_active(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);

        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'permissions_locked' => false,
            'ftp_enabled' => false,
            'ssh_enabled' => false,
        ]);

        $issue = AuditIssue::query()->create([
            'project_id' => $project->id,
            'tool' => 'npm',
            'status' => 'open',
            'severity' => 'high',
            'summary' => 'npm audit found vulnerabilities',
        ]);

        Deployment::query()->create([
            'project_id' => $project->id,
            'triggered_by' => $user->id,
            'action' => 'deploy',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->mockEnterpriseEdition();

        Livewire::actingAs($user)
            ->test(SecurityIndex::class)
            ->call('resolveAuditIssueForce', $issue->id);

        $actions = DeploymentQueueItem::query()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->pluck('action')
            ->all();

        $this->assertSame(['audit_project', 'npm_audit_fix_force'], $actions);
    }

    public function test_force_fix_for_npm_alert_queues_when_queue_is_idle(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);

        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'permissions_locked' => false,
            'ftp_enabled' => false,
            'ssh_enabled' => false,
        ]);

        $alert = SecurityAlert::query()->create([
            'project_id' => $project->id,
            'github_alert_id' => 2003,
            'state' => 'open',
            'severity' => 'high',
            'package_name' => 'vite',
            'ecosystem' => 'npm',
        ]);

        $this->mockEnterpriseEdition();

        $this->mock(DeploymentService::class, function ($mock): void {
            $mock->shouldReceive('releaseStaleRunningDeployments')
                ->once()
                ->andReturnNull();
            $mock->shouldNotReceive('npmAuditFix');
        });

        Livewire::actingAs($user)
            ->test(SecurityIndex::class)
            ->call('resolveSecurityAlertForce', $alert->id);

        $items = DeploymentQueueItem::query()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->get(['action', 'status'])
            ->map(fn (DeploymentQueueItem $item): array => [
                'action' => $item->action,
                'status' => $item->status,
            ])
            ->all();

        $this->assertSame([
            ['action' => 'audit_project', 'status' => 'queued'],
            ['action' => 'npm_audit_fix_force', 'status' => 'queued'],
        ], $items);
    }

    public function test_resolve_all_queues_project_specific_repair_actions(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);

        $user = User::factory()->create();
        $composerProject = Project::factory()->create([
            'user_id' => $user->id,
            'permissions_locked' => false,
            'ftp_enabled' => false,
            'ssh_enabled' => false,
        ]);
        $npmProject = Project::factory()->create([
            'user_id' => $user->id,
            'permissions_locked' => false,
            'ftp_enabled' => false,
            'ssh_enabled' => false,
        ]);

        SecurityAlert::query()->create([
            'project_id' => $composerProject->id,
            'github_alert_id' => 3001,
            'state' => 'open',
            'severity' => 'high',
            'package_name' => 'laravel/framework',
            'ecosystem' => 'composer',
        ]);

        AuditIssue::query()->create([
            'project_id' => $npmProject->id,
            'tool' => 'npm',
            'status' => 'open',
            'severity' => 'moderate',
            'summary' => 'npm audit found issues',
        ]);

        $this->mockEnterpriseEdition();

        Livewire::actingAs($user)
            ->test(SecurityIndex::class)
            ->call('resolveAll');

        $composerActions = DeploymentQueueItem::query()
            ->where('project_id', $composerProject->id)
            ->orderBy('position')
            ->pluck('action')
            ->all();

        $npmActions = DeploymentQueueItem::query()
            ->where('project_id', $npmProject->id)
            ->orderBy('position')
            ->pluck('action')
            ->all();

        $this->assertSame(['audit_project', 'composer_update'], $composerActions);
        $this->assertSame(['audit_project', 'npm_update'], $npmActions);
    }

    public function test_action_center_keeps_project_shell_during_livewire_actions(): void
    {
        config()->set('gitmanager.deploy_queue.enabled', true);

        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'permissions_locked' => false,
            'ftp_enabled' => false,
            'ssh_enabled' => false,
        ]);

        $alert = SecurityAlert::query()->create([
            'project_id' => $project->id,
            'github_alert_id' => 4001,
            'state' => 'open',
            'severity' => 'high',
            'package_name' => 'laravel/framework',
            'ecosystem' => 'composer',
        ]);

        $this->mockEnterpriseEdition();

        Livewire::actingAs($user)
            ->test(SecurityIndex::class)
            ->set('projectShell', true)
            ->call('resolveSecurityAlert', $alert->id)
            ->assertSet('projectShell', true)
            ->assertViewHas('projectShell', true);
    }

    public function test_licensing_page_shows_buy_enterprise_button(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('system.licensing'))
            ->assertOk()
            ->assertSee('Buy Enterprise');
    }

    private function mockEnterpriseEdition(): void
    {
        $this->mock(EditionService::class, function ($mock): void {
            $mock->shouldReceive('current')
                ->andReturn(EditionService::ENTERPRISE);
            $mock->shouldReceive('label')
                ->andReturn('Enterprise Edition');
        });
    }
}
