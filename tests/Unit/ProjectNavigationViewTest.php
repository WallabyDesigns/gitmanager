<?php

namespace Tests\Unit;

use App\Services\NavigationStateService;
use Mockery;
use Tests\TestCase;

class ProjectNavigationViewTest extends TestCase
{
    private function navigation(bool $admin, array $props = []): string
    {
        $service = Mockery::mock(NavigationStateService::class);
        $service->shouldReceive('projectsSidebarState')->once()->andReturn([
            'isAdmin' => $admin, 'isEnterprise' => false,
        ]);
        $this->app->instance(NavigationStateService::class, $service);

        return view('livewire.projects.partials.tabs', $props)->render();
    }

    private function assertCreateOutsideTabs(string $html, string $label): void
    {
        $start = strpos($html, '<nav data-gwm-project-tabs');
        $this->assertNotFalse($start);
        $end = strpos($html, '</nav>', $start);
        $this->assertNotFalse($end);
        $this->assertStringNotContainsString('Create ', substr($html, $start, $end - $start));
        $this->assertSame(1, substr_count($html, 'data-gwm-create'));
        $this->assertGreaterThan($end, strpos($html, 'data-gwm-create'));
        $this->assertStringContainsString('gwm-btn gwm-btn-primary', $html);
        $this->assertStringContainsString($label, $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function test_project_create_action_is_not_a_tab_and_admin_remote_tab_is_preserved(): void
    {
        foreach (['list', 'create'] as $tab) {
            $html = $this->navigation(true, ['projectsTab' => $tab]);
            $this->assertCreateOutsideTabs($html, 'Create Project');
            $this->assertStringContainsString(route('projects.create'), $html);
            $this->assertStringContainsString(route('ftp-accounts.index'), $html);
        }
        $html = $this->navigation(true, ['showBulkActions' => true, 'search' => '']);
        $this->assertCreateOutsideTabs($html, 'Create Project');
        $this->assertStringContainsString('Check Health', $html);
        $this->assertStringContainsString('Check Updates', $html);
    }

    public function test_remote_create_action_uses_existing_livewire_method_not_project_creation(): void
    {
        $html = $this->navigation(true, ['projectsTab' => 'ftp-accounts']);
        $this->assertCreateOutsideTabs($html, 'Create Remote Access');
        $this->assertStringContainsString('wire:click="setTab(\'ftpcreate\')"', $html);
        $this->assertStringNotContainsString(route('projects.create'), $html);
        $source = file_get_contents(resource_path('views/livewire/ftp-accounts/index.blade.php'));
        $this->assertStringNotContainsString("@include('livewire.ftp-accounts.partials.tabs')", $source);
        $this->assertStringContainsString('Back to Remote Access', $source);
    }

    public function test_regular_users_do_not_gain_remote_navigation_or_creation(): void
    {
        $html = $this->navigation(false);
        $this->assertCreateOutsideTabs($html, 'Create Project');
        $this->assertStringNotContainsString('Remote Access', $html);
        $html = $this->navigation(false, ['projectsTab' => 'ftp-accounts']);
        $this->assertStringNotContainsString('data-gwm-create', $html);
        $this->assertStringNotContainsString('Remote Access', $html);
    }
}
