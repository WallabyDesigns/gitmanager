<?php

namespace App\Jobs;

use App\Services\FtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Runs the FTP connection test outside the web request so slow or blocked
 * servers cannot trigger a proxy gateway timeout. The result is stored in the
 * cache under a one-off token the UI polls.
 */
class TestFtpConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const RESULT_TTL_SECONDS = 600;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  string  $encryptedPassword  Crypt-encrypted so the plaintext never lands in the jobs table.
     */
    public function __construct(
        public string $token,
        public string $host,
        public int $port,
        public string $username,
        public string $encryptedPassword,
        public bool $ssl,
        public bool $passive,
        public int $connectTimeout,
        public ?string $rootPath
    ) {}

    public static function resultKey(string $token): string
    {
        return 'ftp-test:'.$token;
    }

    public function handle(FtpService $ftp): void
    {
        try {
            $result = $ftp->testConnection(
                $this->host,
                $this->port,
                $this->username,
                Crypt::decryptString($this->encryptedPassword),
                $this->ssl,
                $this->passive,
                $this->connectTimeout,
                $this->rootPath,
                max($this->connectTimeout, FtpService::SYNC_TEST_TIMEOUT_SECONDS)
            );
        } catch (\Throwable $exception) {
            report($exception);
            $result = ['status' => 'error', 'message' => 'FTP test failed unexpectedly: '.$exception->getMessage()];
        }

        $this->storeResult($result);
    }

    public function failed(\Throwable $exception): void
    {
        $this->storeResult(['status' => 'error', 'message' => 'FTP test could not complete: '.$exception->getMessage()]);
    }

    /**
     * @param  array{status: string, message: string}  $result
     */
    private function storeResult(array $result): void
    {
        Cache::put(self::resultKey($this->token), $result, self::RESULT_TTL_SECONDS);
    }
}
