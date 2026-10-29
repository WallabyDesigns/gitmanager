<?php

namespace Tests\Unit;

use App\Jobs\TestFtpConnection;
use App\Services\FtpService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

class TestFtpConnectionJobTest extends TestCase
{
    private function makeJob(string $token): TestFtpConnection
    {
        return new TestFtpConnection($token, 'ftp.example.com', 21, 'user', Crypt::encryptString('secret'), true, true, 30, '/public_html');
    }

    public function test_stores_service_result_under_token_and_decrypts_password(): void
    {
        $ftp = Mockery::mock(FtpService::class);
        $ftp->shouldReceive('testConnection')
            ->once()
            ->with('ftp.example.com', 21, 'user', 'secret', true, true, 30, '/public_html', 30)
            ->andReturn(['status' => 'ok', 'message' => 'Connection verified.']);

        $this->makeJob('abc')->handle($ftp);

        $this->assertSame(
            ['status' => 'ok', 'message' => 'Connection verified.'],
            Cache::get(TestFtpConnection::resultKey('abc'))
        );
    }

    public function test_exception_is_reported_as_error_result(): void
    {
        $ftp = Mockery::mock(FtpService::class);
        $ftp->shouldReceive('testConnection')->andThrow(new \RuntimeException('boom'));

        $this->makeJob('def')->handle($ftp);

        $result = Cache::get(TestFtpConnection::resultKey('def'));
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('boom', $result['message']);
    }

    public function test_failed_hook_stores_error_result(): void
    {
        $this->makeJob('ghi')->failed(new \RuntimeException('timed out'));

        $result = Cache::get(TestFtpConnection::resultKey('ghi'));
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('timed out', $result['message']);
    }
}
