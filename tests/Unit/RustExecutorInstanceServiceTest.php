<?php

namespace Tests\Unit;

use App\Services\DockerService;
use App\Services\RustExecutorInstanceService;
use Tests\TestCase;

class RustExecutorInstanceServiceTest extends TestCase
{
    public function test_it_starts_the_bundled_executor_with_its_compose_profile(): void
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

        $result = (new RustExecutorInstanceService($docker))->start();

        $this->assertTrue($result['success']);
        $this->assertSame(base_path(), $docker->composeUpCall['workingDirectory']);
        $this->assertSame('rust-executor', $docker->composeUpCall['service']);
        $this->assertSame(['rust-executor'], $docker->composeUpCall['profiles']);
        $this->assertTrue($docker->composeUpCall['build']);
    }

    public function test_it_reports_a_stopped_executor_as_not_installed(): void
    {
        $service = new RustExecutorInstanceService(new class extends DockerService
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function composeAvailable(): bool
            {
                return true;
            }

            public function composeServiceStatus(string $workingDirectory, string $service): array
            {
                return ['success' => true, 'running' => false, 'state' => 'not_created', 'status' => '', 'name' => '', 'error' => ''];
            }
        });

        $status = $service->status();

        $this->assertFalse($status['running']);
        $this->assertSame('not_created', $status['state']);
        $this->assertSame('http://rust-executor:8787/health', $status['endpoint']);
    }
}
