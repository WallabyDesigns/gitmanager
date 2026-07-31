<?php

namespace Tests\Unit;

use App\Services\HtaccessTemplateService;
use Tests\TestCase;

class HtaccessTemplateServiceTest extends TestCase
{
    public function test_laravel_template_blocks_sensitive_files_and_directories(): void
    {
        $template = app(HtaccessTemplateService::class)->forProjectType('laravel');

        $this->assertStringContainsString('[F,L]', $template);
        $this->assertStringContainsString('\\.env', $template);
        $this->assertStringContainsString('\\.git', $template);
        $this->assertStringContainsString('storage(/.*)?', $template);
        $this->assertStringContainsString('vendor(/.*)?', $template);
        $this->assertStringContainsString('bootstrap(/.*)?', $template);
        $this->assertStringContainsString('config(/.*)?', $template);
    }

    public function test_default_template_blocks_storage_vendor_and_git_directories(): void
    {
        $template = app(HtaccessTemplateService::class)->forProjectType('php');

        // <FilesMatch> only matches the requested file's basename, so it
        // cannot block directories like storage/ or vendor/ whose contents
        // have ordinary filenames — that must be covered separately.
        $this->assertStringContainsString('storage(/.*)?', $template);
        $this->assertStringContainsString('vendor(/.*)?', $template);
        $this->assertStringContainsString('node_modules(/.*)?', $template);
        $this->assertStringContainsString('[F,L]', $template);
    }
}
