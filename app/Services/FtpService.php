<?php

namespace App\Services;

use App\Models\FtpAccount;
use App\Models\Project;
class FtpService
{
    /**
     * @var array{host: string, port: int, username: string, password: string, ssl: bool, passive: bool, timeout: int, rootPath: string}|null
     */
    private ?array $syncContext = null;
    /**
     * @return array{status: string, message: string}
     */
    public function testAccount(FtpAccount $account, ?string $rootPath = null): array
    {
        return $this->testConnection(
            $account->host,
            $account->port ?? 21,
            $account->username,
            $account->getDecryptedPassword(),
            (bool) $account->ssl,
            (bool) $account->passive,
            (int) ($account->timeout ?? 30),
            $rootPath ?: $account->root_path
        );
    }

    /**
     * @return array{status: string, message: string}
     */
    public function testConnection(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $ssl,
        bool $passive,
        int $timeout,
        ?string $rootPath
    ): array {
        if (! $this->ftpAvailable($ssl)) {
            return [
                'status' => 'error',
                'message' => $ssl
                    ? 'FTPS requires the PHP ftp extension with SSL support.'
                    : 'FTP requires the PHP ftp extension.',
            ];
        }

        $connection = $this->connect($host, $port, $ssl, $timeout);
        if (! $connection) {
            return ['status' => 'error', 'message' => 'Unable to connect to the FTP server.'];
        }

        if (! @ftp_login($connection, $username, $password)) {
            $this->safeClose($connection);
            return ['status' => 'error', 'message' => 'FTP login failed. Check username/password.'];
        }

        @ftp_pasv($connection, $passive);

        $configuredRootPath = $this->normalizeRemotePath($rootPath ?: '');
        if (! $this->enterRemotePath($connection, $configuredRootPath)) {
            $this->safeClose($connection);
            return ['status' => 'error', 'message' => 'Unable to access the remote root path: '.$configuredRootPath];
        }

        $effectivePath = (string) (@ftp_pwd($connection) ?: '.');
        $writeTest = $this->testDirectoryWrite($connection, $effectivePath);
        if ($writeTest['status'] !== 'ok' && $configuredRootPath === '') {
            $writeTest['message'] .= ' Tip: set a writable FTP Root Path (for example /public_html).';
        }
        $this->safeClose($connection);

        return $writeTest;
    }

    /**
     * Create the required Laravel directory structure on the remote server via FTP.
     * Called after sync for Laravel projects, since storage/ and bootstrap/cache are
     * excluded from the sync and symlinks (public/storage) are skipped entirely.
     *
     * @param array<int, string> $output
     */
    public function ensureRemoteLaravelDirectories(Project $project, array &$output): void
    {
        $project->refresh();
        $project->loadMissing('ftpAccount');
        $account = $project->ftpAccount;
        if (! $account) {
            return;
        }

        $rootPath = $this->resolveRootPath($project);
        if ($rootPath === '') {
            return;
        }

        if (! $this->ftpAvailable((bool) $account->ssl)) {
            return;
        }

        $connection = $this->connect(
            $account->host,
            (int) ($account->port ?? 21),
            (bool) $account->ssl,
            (int) ($account->timeout ?? 30)
        );

        if (! $connection) {
            $output[] = 'Warning: unable to connect to FTP to create Laravel directory structure.';
            return;
        }

        try {
            if (! @ftp_login($connection, $account->username, $account->getDecryptedPassword())) {
                $output[] = 'Warning: FTP login failed while creating Laravel directory structure.';
                return;
            }

            @ftp_pasv($connection, (bool) $account->passive);

            $normalizedRoot = $this->normalizeRemotePath($rootPath);
            if ($normalizedRoot !== '' && $normalizedRoot !== '.') {
                if (! @ftp_chdir($connection, $normalizedRoot)) {
                    $output[] = 'Warning: unable to access FTP root path for Laravel directory setup: '.$normalizedRoot;
                    return;
                }
            }

            $directories = [
                'storage',
                'storage/app',
                'storage/app/public',
                'storage/framework',
                'storage/framework/cache',
                'storage/framework/cache/data',
                'storage/framework/sessions',
                'storage/framework/views',
                'storage/logs',
                'bootstrap/cache',
                'public/storage',
            ];

            $output[] = 'Ensuring remote Laravel directory structure.';
            foreach ($directories as $dir) {
                try {
                    $this->ensureRemoteDirectory($connection, $dir);
                } catch (\RuntimeException $exception) {
                    $output[] = 'Warning: could not create remote directory '.$dir.': '.$exception->getMessage();
                }
            }
            $output[] = 'Remote Laravel directory structure verified.';
        } finally {
            $this->safeClose($connection);
        }
    }

    /**
     * @param array<int, string> $excludePaths
     */
    public function sync(Project $project, string $localPath, array $excludePaths, array &$output): void
    {
        $project->refresh();
        $project->loadMissing('ftpAccount');
        $account = $project->ftpAccount;
        if (! $account) {
            throw new \RuntimeException('FTP/SSH access record not configured for this project.');
        }

        $rootPath = $this->resolveRootPath($project);
        if ($rootPath === '') {
            throw new \RuntimeException('Remote Root Path is required for FTP sync. Set it on the project or selected FTP/SSH access record.');
        }

        if (! $this->ftpAvailable((bool) $account->ssl)) {
            throw new \RuntimeException('FTP extension not available for this PHP installation.');
        }

        $this->syncContext = [
            'host' => $account->host,
            'port' => (int) ($account->port ?? 21),
            'username' => $account->username,
            'password' => $account->getDecryptedPassword(),
            'ssl' => (bool) $account->ssl,
            'passive' => (bool) $account->passive,
            'timeout' => (int) ($account->timeout ?? 30),
            'rootPath' => $this->normalizeRemotePath($rootPath),
        ];

        $connection = $this->openSyncConnection($output);

        $passiveInUse = (bool) $this->syncContext['passive'];
        $writeTest = $this->verifyWritableConnection(
            $connection,
            (string) (@ftp_pwd($connection) ?: '.'),
            $passiveInUse,
            true
        );
        if ($passiveInUse !== (bool) $this->syncContext['passive']) {
            $this->syncContext['passive'] = $passiveInUse;
            $output[] = 'FTPS write test required passive mode '.($passiveInUse ? 'on' : 'off').' for this session.';
        }
        if ($writeTest['status'] !== 'ok') {
            $output[] = 'FTPS write test failed: '.$writeTest['message'];
            $this->safeClose($connection);
            $this->syncContext = null;
            throw new \RuntimeException($writeTest['message']);
        }

        $stats = [
            'files' => 0,
            'uploaded' => 0,
            'skipped' => 0,
            'directories' => 0,
        ];

        $output[] = 'Starting FTPS sync to '.$account->host.($rootPath && $rootPath !== '.' ? ' ('.$rootPath.')' : '').'.';

        try {
            $this->syncDirectory($connection, $localPath, '', $excludePaths, $stats, $output);
        } finally {
            $this->safeClose($connection);
            $this->syncContext = null;
        }

        $output[] = sprintf(
            'FTPS sync finished. Uploaded %d of %d files (%d skipped).',
            $stats['uploaded'],
            $stats['files'],
            $stats['skipped']
        );
    }

    /**
     * @param array<int, string> $files
     * @param array<int, string> $output
     */
    public function syncFiles(Project $project, string $localRoot, array $files, array &$output): void
    {
        $project->refresh();
        $project->loadMissing('ftpAccount');
        $account = $project->ftpAccount;
        if (! $account) {
            throw new \RuntimeException('FTP/SSH access record not configured for this project.');
        }

        $rootPath = $this->resolveRootPath($project);
        if ($rootPath === '') {
            throw new \RuntimeException('Remote Root Path is required for FTP sync. Set it on the project or selected FTP/SSH access record.');
        }

        if (! $this->ftpAvailable((bool) $account->ssl)) {
            throw new \RuntimeException('FTP extension not available for this PHP installation.');
        }

        $files = array_values(array_unique(array_filter(array_map('trim', $files), fn (string $file) => $file !== '')));
        if ($files === []) {
            $output[] = 'FTPS file sync skipped: no files to upload.';
            return;
        }

        $this->syncContext = [
            'host' => $account->host,
            'port' => (int) ($account->port ?? 21),
            'username' => $account->username,
            'password' => $account->getDecryptedPassword(),
            'ssl' => (bool) $account->ssl,
            'passive' => (bool) $account->passive,
            'timeout' => (int) ($account->timeout ?? 30),
            'rootPath' => $this->normalizeRemotePath($rootPath),
        ];

        $connection = $this->openSyncConnection($output);

        $passiveInUse = (bool) $this->syncContext['passive'];
        $writeTest = $this->verifyWritableConnection(
            $connection,
            (string) (@ftp_pwd($connection) ?: '.'),
            $passiveInUse,
            true
        );
        if ($passiveInUse !== (bool) $this->syncContext['passive']) {
            $this->syncContext['passive'] = $passiveInUse;
            $output[] = 'FTPS write test required passive mode '.($passiveInUse ? 'on' : 'off').' for this session.';
        }
        if ($writeTest['status'] !== 'ok') {
            $output[] = 'FTPS write test failed: '.$writeTest['message'];
            $this->safeClose($connection);
            $this->syncContext = null;
            throw new \RuntimeException($writeTest['message']);
        }

        $stats = [
            'files' => 0,
            'uploaded' => 0,
            'skipped' => 0,
        ];

        $output[] = 'Starting FTPS file sync to '.$account->host.($rootPath && $rootPath !== '.' ? ' ('.$rootPath.')' : '').'.';

        try {
            foreach ($files as $relative) {
                $relative = ltrim(str_replace(['\\', '/'], '/', $relative), '/');
                if ($relative === '') {
                    continue;
                }

                $stats['files']++;
                $localPath = rtrim($localRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (! is_file($localPath)) {
                    $stats['skipped']++;
                    continue;
                }

                $remotePath = $relative;
                $remoteDir = dirname($remotePath);
                if ($remoteDir !== '.' && $remoteDir !== '') {
                    $this->ensureRemoteDirectory($connection, $remoteDir);
                }

                if (! @ftp_put($connection, $remotePath, $localPath, FTP_BINARY)) {
                    if (! $this->retryUpload($connection, $remotePath, $localPath, $relative, $output)) {
                        throw new \RuntimeException('Failed to upload '.$relative.' to '.$remotePath.'. Check FTP write permissions or exclude this path.');
                    }
                }

                $stats['uploaded']++;
            }
        } finally {
            $this->safeClose($connection);
            $this->syncContext = null;
        }

        $output[] = sprintf(
            'FTPS file sync finished. Uploaded %d of %d files (%d skipped).',
            $stats['uploaded'],
            $stats['files'],
            $stats['skipped']
        );
    }

    /**
     * @param array<int, string> $paths
     * @return array<string, string|null>
     */
    public function fetchRemoteFiles(Project $project, array $paths, array &$output): array
    {
        $project->refresh();
        $project->loadMissing('ftpAccount');
        $account = $project->ftpAccount;
        if (! $account) {
            $output[] = 'FTP-only pipeline skipped: no FTP/SSH access record configured.';
            return [];
        }

        if (! $this->ftpAvailable((bool) $account->ssl)) {
            $output[] = 'FTP-only pipeline skipped: FTP extension not available for this PHP installation.';
            return [];
        }

        $paths = array_values(array_unique(array_filter(array_map('trim', $paths), fn (string $path) => $path !== '')));
        if ($paths === []) {
            return [];
        }

        $connection = $this->connect(
            $account->host,
            (int) ($account->port ?? 21),
            (bool) $account->ssl,
            (int) ($account->timeout ?? 30)
        );

        if (! $connection) {
            $output[] = 'FTP-only pipeline skipped: unable to connect to the FTP server.';
            return [];
        }

        try {
            if (! @ftp_login($connection, $account->username, $account->getDecryptedPassword())) {
                $output[] = 'FTP-only pipeline skipped: FTP login failed.';
                return [];
            }

            @ftp_pasv($connection, (bool) $account->passive);

            $rootPath = $this->resolveRootPath($project);
            if ($rootPath === '') {
                $output[] = 'FTP-only pipeline skipped: Remote Root Path is not configured.';
                return [];
            }
            $rootPath = $this->normalizeRemotePath($rootPath);
            if ($rootPath !== '' && $rootPath !== '.') {
                if (! @ftp_chdir($connection, $rootPath)) {
                    $output[] = 'FTP-only pipeline skipped: unable to access the remote root path: '.$rootPath;
                    return [];
                }
            }

            $results = [];
            foreach ($paths as $relative) {
                $relative = ltrim(str_replace('\\', '/', $relative), '/');
                if ($relative === '') {
                    continue;
                }

                $temp = tempnam(sys_get_temp_dir(), 'gwm-ftp');
                if (! $temp) {
                    $results[$relative] = null;
                    continue;
                }

                $success = @ftp_get($connection, $temp, $relative, FTP_BINARY);
                if (! $success) {
                    @unlink($temp);
                    $results[$relative] = null;
                    continue;
                }

                $contents = @file_get_contents($temp);
                @unlink($temp);
                $results[$relative] = $contents === false ? null : $contents;
            }

            return $results;
        } finally {
            $this->safeClose($connection);
        }
    }

    private function ftpAvailable(bool $ssl): bool
    {
        if ($ssl) {
            return function_exists('ftp_ssl_connect');
        }

        return function_exists('ftp_connect');
    }

    private function connect(string $host, int $port, bool $ssl, int $timeout): mixed
    {
        if ($ssl && function_exists('ftp_ssl_connect')) {
            $connection = @ftp_ssl_connect($host, $port, $timeout);
            if ($connection && function_exists('ftp_set_option')) {
                @ftp_set_option($connection, FTP_TIMEOUT_SEC, $timeout);
            }
            return $connection;
        }

        if (function_exists('ftp_connect')) {
            $connection = @ftp_connect($host, $port, $timeout);
            if ($connection && function_exists('ftp_set_option')) {
                @ftp_set_option($connection, FTP_TIMEOUT_SEC, $timeout);
            }
            return $connection;
        }

        return false;
    }

    /**
     * @param array<int, string> $output
     * @return resource|\FTP\Connection
     */
    private function openSyncConnection(array &$output)
    {
        if (! $this->syncContext) {
            throw new \RuntimeException('FTP sync context missing.');
        }

        $context = $this->syncContext;
        $connection = $this->connect(
            $context['host'],
            $context['port'],
            $context['ssl'],
            $context['timeout']
        );

        if (! $connection) {
            throw new \RuntimeException('Unable to connect to the FTP server.');
        }

        if (! @ftp_login($connection, $context['username'], $context['password'])) {
            $this->safeClose($connection);
            throw new \RuntimeException('FTP login failed. Check username/password.');
        }

        @ftp_pasv($connection, $context['passive']);

        $rootPath = $context['rootPath'];
        if ($rootPath !== '' && $rootPath !== '.') {
            if (! @ftp_chdir($connection, $rootPath)) {
                $this->ensureRemoteDirectory($connection, $rootPath);
            }
            if (! @ftp_chdir($connection, $rootPath)) {
                $this->safeClose($connection);
                throw new \RuntimeException('Unable to access the remote root path: '.$rootPath);
            }
        }

        return $connection;
    }

    /**
     * @param array<int, string> $output
     * @param resource|\FTP\Connection|null $connection
     * @return resource|\FTP\Connection
     */
    private function reconnectSyncConnection(array &$output, $connection = null)
    {
        $output[] = 'FTPS retry failed. Reconnecting and retrying once.';
        $this->safeClose($connection);

        try {
            return $this->openSyncConnection($output);
        } catch (\Throwable $exception) {
            $output[] = 'FTPS reconnect failed: '.$exception->getMessage();
            throw $exception;
        }
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function enterRemotePath($connection, string $rootPath): bool
    {
        $rootPath = $this->normalizeRemotePath($rootPath);
        if ($rootPath === '' || $rootPath === '.') {
            return true;
        }

        if (@ftp_chdir($connection, $rootPath)) {
            return true;
        }

        try {
            $this->ensureRemoteDirectory($connection, $rootPath);
        } catch (\Throwable $e) {
            return false;
        }

        return @ftp_chdir($connection, $rootPath);
    }

    /**
     * @param resource|\FTP\Connection $connection
     * @param bool $passive
     * @return array{status: string, message: string}
     */
    private function verifyWritableConnection($connection, string $displayPath, bool &$passive, bool $allowPassiveToggle = true): array
    {
        $displayPath = trim($displayPath) !== '' ? trim($displayPath) : '.';
        $initial = $this->testWriteWithPathFallback($connection, $displayPath);
        if ($initial['status'] === 'ok') {
            return $initial;
        }

        if (! $allowPassiveToggle) {
            return $initial;
        }

        $toggled = ! $passive;
        @ftp_pasv($connection, $toggled);
        $retry = $this->testWriteWithPathFallback($connection, $displayPath);
        if ($retry['status'] === 'ok') {
            $passive = $toggled;
            $retry['message'] .= ' Connection succeeded after switching passive mode '.($toggled ? 'on' : 'off').'.';
            return $retry;
        }

        @ftp_pasv($connection, $passive);
        $initial['message'] .= ' Try toggling passive mode in FTP/SSH Access.';

        return $initial;
    }

    /**
     * @param resource|\FTP\Connection $connection
     * @return array{status: string, message: string}
     */
    private function testWriteWithPathFallback($connection, string $displayPath): array
    {
        $result = $this->testWrite($connection, '.', $displayPath);
        if ($result['status'] === 'ok') {
            return $result;
        }

        $pwd = $this->normalizeRemotePath((string) (@ftp_pwd($connection) ?: ''));
        if ($pwd !== '' && $pwd !== '.') {
            $absolute = $this->testWrite($connection, $pwd, $displayPath);
            if ($absolute['status'] === 'ok') {
                return $absolute;
            }
        }

        return $result;
    }

    /**
     * Lightweight write check used for UI test buttons to avoid long transfer
     * timeouts behind proxies/load balancers. Actual file upload checks still
     * run during FTP sync.
     *
     * @param resource|\FTP\Connection $connection
     * @return array{status: string, message: string}
     */
    private function testDirectoryWrite($connection, string $displayPath): array
    {
        $displayPath = trim($displayPath) !== '' ? trim($displayPath) : '.';
        $directory = '.gwm-test-dir-'.uniqid();

        $created = @ftp_mkdir($connection, $directory);
        if ($created === false) {
            return ['status' => 'warning', 'message' => 'Connected, but unable to write to the remote path: '.$displayPath];
        }

        $createdPath = is_string($created) ? $created : $directory;
        @ftp_rmdir($connection, $createdPath);
        if ($createdPath !== $directory) {
            @ftp_rmdir($connection, $directory);
        }

        return ['status' => 'ok', 'message' => 'Connection verified and remote path is writable.'];
    }

    private function normalizeRemotePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        return rtrim($path, '/');
    }

    private function resolveRootPath(Project $project): string
    {
        $baseRoot = trim((string) ($project->ftp_root_path ?? ''));
        if ($baseRoot === '') {
            $baseRoot = trim((string) ($project->ftpAccount->root_path ?? ''));
        }
        $baseRoot = $this->normalizeRemotePath($baseRoot);

        // For FTP projects, local_path represents the remote subdirectory under
        // the configured FTP root. Example: {ftp_root_path}/{local_path}.
        $projectPath = trim((string) ($project->local_path ?? ''));
        if ($projectPath !== '' && $this->looksLikeRemotePath($projectPath)) {
            $normalizedProjectPath = $this->normalizeRemotePath($projectPath);
            if ($normalizedProjectPath !== '' && $normalizedProjectPath !== '.') {
                if ($baseRoot !== '' && $this->remotePathIncludesBase($normalizedProjectPath, $baseRoot)) {
                    // Compatibility: if local_path already contains the base
                    // root, do not prefix it again.
                    return $normalizedProjectPath;
                }

                $relativeProjectPath = ltrim($normalizedProjectPath, '/');
                if ($relativeProjectPath !== '') {
                    return $baseRoot !== ''
                        ? $this->joinRemotePath($baseRoot, $relativeProjectPath)
                        : $relativeProjectPath;
                }
            }
        }

        return $baseRoot;
    }

    private function looksLikeRemotePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Windows absolute paths are local filesystem paths, not FTP roots.
        if (preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1) {
            return false;
        }

        // Backslashes strongly indicate a local path on Windows.
        if (str_contains($path, '\\')) {
            return false;
        }

        return true;
    }

    private function remotePathIncludesBase(string $path, string $base): bool
    {
        $path = ltrim($this->normalizeRemotePath($path), '/');
        $base = ltrim($this->normalizeRemotePath($base), '/');

        if ($path === '' || $base === '') {
            return false;
        }

        return $path === $base || str_starts_with($path, $base.'/');
    }

    /**
     * @param resource|\FTP\Connection $connection
     * @param array<int, string> $excludePaths
     * @param array{files: int, uploaded: int, skipped: int, directories: int} $stats
     */
    private function syncDirectory(&$connection, string $localPath, string $remoteRoot, array $excludePaths, array &$stats, array &$output): void
    {
        $localPath = rtrim($localPath, DIRECTORY_SEPARATOR);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }

            $relative = $iterator->getSubPathname();
            $relative = str_replace(['\\', '/'], '/', $relative);

            if ($this->shouldExclude($relative, $excludePaths)) {
                continue;
            }

            $remotePath = $this->joinRemotePath($remoteRoot, $relative);

            if ($item->isDir()) {
                $this->ensureRemoteDirectory($connection, $remotePath);
                $stats['directories']++;
                continue;
            }

            $stats['files']++;
            if (! $this->shouldUpload($connection, $remotePath, $item->getPathname())) {
                $stats['skipped']++;
                continue;
            }

            $this->ensureRemoteDirectory($connection, dirname($remotePath));

            if (! @ftp_put($connection, $remotePath, $item->getPathname(), FTP_BINARY)) {
                if (! $this->retryUpload($connection, $remotePath, $item->getPathname(), $relative, $output)) {
                    throw new \RuntimeException('Failed to upload '.$relative.' to '.$remotePath.'. Check FTP write permissions or exclude this path.');
                }
            }

            $stats['uploaded']++;
        }
    }

    /**
     * @param resource|\FTP\Connection $connection
     * @param array<int, string> $output
     */
    private function retryUpload(&$connection, string $remotePath, string $localPath, string $relative, array &$output): bool
    {
        $output[] = 'FTPS upload failed for '.$relative.'. Retrying with permission fix.';

        $this->ensureRemoteDirectory($connection, dirname($remotePath));

        $this->attemptRemotePermissionFix($connection, dirname($remotePath), $remotePath);

        if (@ftp_put($connection, $remotePath, $localPath, FTP_BINARY)) {
            return true;
        }

        try {
            $connection = $this->reconnectSyncConnection($output, $connection);
        } catch (\Throwable $exception) {
            return false;
        }

        $this->ensureRemoteDirectory($connection, dirname($remotePath));
        $this->attemptRemotePermissionFix($connection, dirname($remotePath), $remotePath);

        if (@ftp_put($connection, $remotePath, $localPath, FTP_BINARY)) {
            return true;
        }

        $this->testRemoteDirectoryWritable($connection, dirname($remotePath), $output);

        return false;
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function attemptRemotePermissionFix($connection, string $remoteDir, string $remoteFile): void
    {
        if (function_exists('ftp_chmod')) {
            @ftp_chmod($connection, 0755, $remoteDir);
            @ftp_chmod($connection, 0644, $remoteFile);
        }

        @ftp_delete($connection, $remoteFile);
    }

    /**
     * @param resource|\FTP\Connection $connection
     * @param array<int, string> $output
     */
    private function testRemoteDirectoryWritable($connection, string $remoteDir, array &$output): void
    {
        $remoteDir = $this->normalizeRemotePath($remoteDir);
        $filename = '.gwm-write-test-'.uniqid().'.tmp';
        $remote = ($remoteDir === '' || $remoteDir === '.') ? $filename : $remoteDir.'/'.$filename;

        $temp = tempnam(sys_get_temp_dir(), 'gwm');
        if (! $temp) {
            return;
        }

        @file_put_contents($temp, 'gwm');
        $success = @ftp_put($connection, $remote, $temp, FTP_BINARY);
        @unlink($temp);

        if (! $success) {
            $output[] = 'FTPS write test failed in '.($remoteDir === '' ? '(root)' : $remoteDir).'. Check permissions, ownership, or disk quota.';
            return;
        }

        @ftp_delete($connection, $remote);
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function ensureRemoteDirectory($connection, string $remotePath): void
    {
        $remotePath = $this->normalizeRemotePath($remotePath);
        if ($remotePath === '' || $remotePath === '.') {
            return;
        }

        $original = @ftp_pwd($connection);
        $segments = array_values(array_filter(explode('/', $remotePath), fn ($segment) => $segment !== ''));
        $cursor = '';

        foreach ($segments as $segment) {
            $cursor = $cursor === '' ? $segment : $cursor.'/'.$segment;
            if (@ftp_chdir($connection, $cursor)) {
                // Restore CWD so the next iteration's $cursor is still relative to $original
                if ($original) {
                    @ftp_chdir($connection, $original);
                }
                continue;
            }

            if (! @ftp_mkdir($connection, $cursor)) {
                if ($original) {
                    @ftp_chdir($connection, $original);
                }
                throw new \RuntimeException('Unable to create remote directory: '.$cursor);
            }

            $this->attemptRemoteDirectoryPermissions($connection, $cursor);

            if (! @ftp_chdir($connection, $cursor)) {
                $this->attemptRemoteDirectoryPermissions($connection, $cursor);
                if (! @ftp_chdir($connection, $cursor)) {
                    if ($original) {
                        @ftp_chdir($connection, $original);
                    }
                    throw new \RuntimeException('Unable to access remote directory: '.$cursor);
                }
            }

            // Restore CWD so the next iteration's $cursor is still relative to $original
            if ($original) {
                @ftp_chdir($connection, $original);
            }
        }

        if ($original) {
            @ftp_chdir($connection, $original);
        }
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function attemptRemoteDirectoryPermissions($connection, string $remoteDir): void
    {
        if (function_exists('ftp_chmod')) {
            @ftp_chmod($connection, 0775, $remoteDir);
        }
    }

    private function joinRemotePath(string $root, string $relative): string
    {
        $root = $this->normalizeRemotePath($root);
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($root === '' || $root === '.') {
            return $relative;
        }

        return $root.'/'.$relative;
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function shouldUpload($connection, string $remotePath, string $localPath): bool
    {
        $remoteSize = @ftp_size($connection, $remotePath);
        $localSize = @filesize($localPath);

        if ($remoteSize === -1 || $localSize === false) {
            return true;
        }

        if ($remoteSize !== $localSize) {
            return true;
        }

        $remoteMTime = @ftp_mdtm($connection, $remotePath);
        $localMTime = @filemtime($localPath);

        if ($remoteMTime === -1 || $localMTime === false) {
            return false;
        }

        return $remoteMTime < $localMTime;
    }

    /**
     * @param array<int, string> $excludePaths
     */
    private function shouldExclude(string $relative, array $excludePaths): bool
    {
        $relative = ltrim($relative, '/');

        foreach ($excludePaths as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern));
            if ($pattern === '') {
                continue;
            }

            $pattern = ltrim($pattern, '/');
            if (str_contains($pattern, '*')) {
                if (fnmatch($pattern, $relative)) {
                    return true;
                }
                continue;
            }

            if ($relative === $pattern || str_starts_with($relative, rtrim($pattern, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param resource|\FTP\Connection $connection
     * @return array{status: string, message: string}
     */
    private function testWrite($connection, string $rootPath, ?string $displayPath = null): array
    {
        $rootPath = $rootPath === '' ? '.' : $rootPath;
        $filename = 'gwm-test-'.uniqid().'.txt';
        $remoteRoot = rtrim($rootPath, '/');
        $remote = ($remoteRoot === '' || $remoteRoot === '.') ? $filename : $remoteRoot.'/'.$filename;
        $displayPath = trim((string) ($displayPath ?? $remoteRoot));
        if ($displayPath === '') {
            $displayPath = '.';
        }

        $temp = tempnam(sys_get_temp_dir(), 'gwm');
        if (! $temp) {
            return ['status' => 'warning', 'message' => 'Connected, but unable to create a local temp file for write test.'];
        }

        file_put_contents($temp, 'gwm');
        $success = @ftp_put($connection, $remote, $temp, FTP_BINARY);
        @unlink($temp);

        if (! $success) {
            return ['status' => 'warning', 'message' => 'Connected, but unable to write to the remote path: '.$displayPath];
        }

        @ftp_delete($connection, $remote);

        return ['status' => 'ok', 'message' => 'Connection verified and remote path is writable.'];
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function safeClose($connection): void
    {
        if ($connection) {
            @ftp_close($connection);
        }
    }
}
