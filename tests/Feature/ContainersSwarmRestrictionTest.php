<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainersSwarmRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_community_users_cannot_open_the_swarm_section(): void
    {
        $user = User::factory()->create();

        $this->mock(EditionService::class, function ($mock): void {
            $mock->shouldReceive('current')->andReturn(EditionService::COMMUNITY);
            $mock->shouldReceive('label')->andReturn('Community Edition');
        });

        $response = $this->actingAs($user)->get(route('infra.containers.section', 'swarm'));

        $response
            ->assertOk()
            ->assertDontSee('Cluster and service controls for Docker Swarm.')
            ->assertDontSee('Docker Swarm is not active');
    }
}
