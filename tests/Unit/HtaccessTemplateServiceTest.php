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

    public function test_laravel_template_does_not_unconditionally_block_storage_and_vendor(): void
    {
        // storage/ and vendor/ back legitimate public-facing paths in a
        // Laravel app: the storage:link symlink and vendor:publish assets
        // (e.g. this app's own asset('vendor/gitmanager-enterprise/...')
        // stylesheet, published under public/vendor/). Both are requested
        // as /storage/... or /vendor/... and must fall through to the
        // /public/ rewrite rather than being blocked outright — only the
        // real top-level storage/ and vendor/ folders should be denied.
        $template = app(HtaccessTemplateService::class)->forProjectType('laravel');

        $lines = explode("\n", $template);
        $ruleIndex = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, 'storage(/.*)?') && str_contains($line, 'vendor(/.*)?')) {
                $ruleIndex = $index;
                break;
            }
        }

        $this->assertNotNull($ruleIndex, 'Expected a combined storage/vendor RewriteRule.');

        $precedingLines = implode("\n", array_slice($lines, max(0, $ruleIndex - 3), 3));
        $this->assertStringContainsString('-f', $precedingLines);
        $this->assertStringContainsString('-d', $precedingLines);

        // The unconditional deny rule must NOT itself contain storage/vendor.
        $unconditionalRuleLine = null;
        foreach ($lines as $line) {
            if (str_contains($line, '\\.env') && str_contains($line, '[F,L]')) {
                $unconditionalRuleLine = $line;
                break;
            }
        }
        $this->assertNotNull($unconditionalRuleLine);
        $this->assertStringNotContainsString('storage(/.*)?', $unconditionalRuleLine);
        $this->assertStringNotContainsString('vendor(/.*)?', $unconditionalRuleLine);
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
