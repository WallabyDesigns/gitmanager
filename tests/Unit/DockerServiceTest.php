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

    public function test_parse_run_command_supports_multiline_pasted_commands(): void
    {
        $service = app(DockerService::class);

        $parsed = $service->parseRunCommand(<<<'CMD'
docker run -d \
  --name web \
  -p 8080:80 \
  -e APP_ENV=production \
  -v web_data:/var/www/html \
  --restart unless-stopped \
  nginx:alpine
CMD);

        $this->assertSame('nginx:alpine', $parsed['image']);
        $this->assertSame('web', $parsed['name']);
        $this->assertSame(['8080:80'], $parsed['ports']);
        $this->assertSame(['APP_ENV=production'], $parsed['env']);
        $this->assertSame(['web_data:/var/www/html'], $parsed['volumes']);
        $this->assertSame('unless-stopped', $parsed['restart']);
    }
}
