<?php

namespace Tests\Unit;

use App\Services\DockerService;
use Tests\TestCase;

class DockerServiceTest extends TestCase
{
    public function test_missing_docker_binary_is_reported_as_unavailable_without_throwing(): void
    {
        config(['gitmanager.docker.binary' => 'gwm-missing-docker-binary']);

        $service = app(DockerService::class);

        $this->assertFalse($service->isAvailable());
        $this->assertSame([], $service->listContainers());
    }
}
