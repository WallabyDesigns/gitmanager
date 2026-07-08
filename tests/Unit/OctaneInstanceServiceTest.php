<?php

namespace Tests\Unit;

use App\Services\DockerService;
use App\Services\OctaneInstanceService;
use Tests\TestCase;

class OctaneInstanceServiceTest extends TestCase
{
    public function test_status_reports_docker_unavailable_without_compose_lookup(): void
    {
        $service = new OctaneInstanceService(new class extends DockerService
        {
            public function isAvailable(): bool
            {
                return false;
            }

            public function composeAvailable(): bool
            {
                throw new \RuntimeException('Compose should not be checked when Docker is unavailable.');
            }
        });

        $status = $service->status();

        $this->assertFalse($status['docker_available']);
        $this->assertFalse($status['running']);
        $this->assertSame('unavailable', $status['state']);
    }

    public function test_start_uses_octane_compose_profile_and_service(): void
    {
        $docker = new class extends DockerService
        {
            public array $composeUpCall = [];

            public function isAvailable(): bool
            {
                return true;
            }

            public function composeAvailable(): bool
            {
                return true;
            }

            public function composeUp(string $workingDirectory, string $service, array $profiles = [], bool $build = false): array
            {
                $this->composeUpCall = compact('workingDirectory', 'service', 'profiles', 'build');

                return ['success' => true, 'output' => '', 'error' => ''];
            }
        };

        $service = new OctaneInstanceService($docker);

        $result = $service->start();

        $this->assertTrue($result['success']);
        $this->assertSame(base_path(), $docker->composeUpCall['workingDirectory']);
        $this->assertSame('octane', $docker->composeUpCall['service']);
        $this->assertSame(['octane'], $docker->composeUpCall['profiles']);
        $this->assertTrue($docker->composeUpCall['build']);
    }
}
