<?php

namespace Tests\Unit;

use App\Services\RuntimeDiagnosticsService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RuntimeDiagnosticsServiceTest extends TestCase
{
    public function test_detect_includes_rust_and_cargo_entries(): void
    {
        Cache::forget('runtime_diagnostics');

        $diagnostics = app(RuntimeDiagnosticsService::class)->detect();

        $this->assertArrayHasKey('rustc', $diagnostics);
        $this->assertArrayHasKey('cargo', $diagnostics);
        $this->assertSame('Rust', $diagnostics['rustc']['label']);
        $this->assertSame('Cargo', $diagnostics['cargo']['label']);
        $this->assertArrayHasKey('found', $diagnostics['rustc']);
        $this->assertArrayHasKey('found', $diagnostics['cargo']);
    }

    public function test_missing_rust_reports_rustup_install_guidance(): void
    {
        Cache::forget('runtime_diagnostics');

        $diagnostics = app(RuntimeDiagnosticsService::class)->detect();

        if ($diagnostics['rustc']['found']) {
            $this->markTestSkipped('Rust is installed on this host; guidance is only shown when missing.');
        }

        $this->assertNotNull($diagnostics['rustc']['guidance']);
        $this->assertStringContainsString('rust', strtolower($diagnostics['rustc']['guidance']));
    }
}
