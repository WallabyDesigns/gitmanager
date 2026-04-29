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
    public function sync(Project $project, string $localPath, array $excludePaths, array &$output, array $whitelistPaths = []): void
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
        $output[] = 'FTPS sync preflight connected to '.((string) (@ftp_pwd($connection) ?: '.')).'.';

        $stats = [
            'files' => 0,
            'uploaded' => 0,
            'skipped' => 0,
            'directories' => 0,
        ];

        $excludePaths = $this->normalizePathPatterns($excludePaths);
        $whitelistPaths = $this->normalizePathPatterns($whitelistPaths);

        $output[] = 'Starting FTPS sync to '.$account->host.($rootPath && $rootPath !== '.' ? ' ('.$rootPath.')' : '').'.';

        try {
            $this->syncDirectory($connection, $localPath, '', $excludePaths, $whitelistPaths, $stats, $output);
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

        $excludePaths = $this->projectExcludePaths($project);
        $whitelistPaths = $this->projectWhitelistPaths($project);
        if ($whitelistPaths !== [] || $excludePaths !== []) {
            $files = array_values(array_filter($files, function (string $relative) use ($excludePaths, $whitelistPaths): bool {
                $relative = ltrim(str_replace(['\\', '/'], '/', $relative), '/');

                if ($relative === '') {
                    return false;
                }

                if (! $this->shouldInclude($relative, $whitelistPaths)) {
                    return false;
                }

                return ! $this->shouldExclude($relative, $excludePaths);
            }));
        }

        if ($files === []) {
            $output[] = 'FTPS file sync skipped: all candidate files were filtered by whitelist/excluded paths.';
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
        $output[] = 'FTPS file sync preflight connected to '.((string) (@ftp_pwd($connection) ?: '.')).'.';

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

            $effectiveRoot = (string) (@ftp_pwd($connection) ?: $rootPath);
            $output[] = 'FTP manifest fetch root: '.$effectiveRoot;

            $results = [];
            foreach ($paths as $relative) {
                $relative = ltrim(str_replace('\\', '/', $relative), '/');
                if ($relative === '') {
                    continue;
                }

                $contents = $this->downloadRemoteFile($connection, $relative, $effectiveRoot);
                $results[$relative] = $contents;
                if ($contents === null) {
                    $output[] = 'FTP manifest fetch: '.$relative.' was not found at '.$this->joinRemotePath($effectiveRoot, $relative).'.';
                }
            }

            $this->logManifestLocationHints($connection, $effectiveRoot, $paths, $results, $output);

            return $results;
        } finally {
            $this->safeClose($connection);
        }
    }

    public function resolvedRootPath(Project $project): string
    {
        $project->loadMissing('ftpAccount');

        return $this->resolveRootPath($project);
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function downloadRemoteFile($connection, string $relative, ?string $effectiveRoot = null): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return null;
        }

        $candidates = [$relative, './'.$relative];
        $effectiveRoot = $effectiveRoot !== null ? $this->normalizeRemotePath($effectiveRoot) : '';
        if ($effectiveRoot !== '' && $effectiveRoot !== '.') {
            $candidates[] = $this->joinRemotePath($effectiveRoot, $relative);
        }
        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $candidate) {
            $contents = $this->downloadRemoteFileCandidate($connection, $candidate);
            if ($contents !== null) {
                return $contents;
            }
        }

        return null;
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function downloadRemoteFileCandidate($connection, string $remotePath): ?string
    {
        $temp = tempnam(sys_get_temp_dir(), 'gwm-ftp');
        if (! $temp) {
            return null;
        }

        $success = @ftp_get($connection, $temp, $remotePath, FTP_BINARY);
        if (! $success) {
            @unlink($temp);
            return null;
        }

        $contents = @file_get_contents($temp);
        @unlink($temp);

        return $contents === false ? null : $contents;
    }

    /**
     * @param resource|\FTP\Connection $connection
     * @param array<int, string> $paths
     * @param array<string, string|null> $results
     * @param array<int, string> $output
     */
    private function logManifestLocationHints($connection, string $effectiveRoot, array $paths, array $results, array &$output): void
    {
        $missing = array_values(array_filter(array_map(
            fn (string $path) => ltrim(str_replace('\\', '/', trim($path)), '/'),
            $paths
        ), fn (string $path) => $path !== '' && ($results[$path] ?? null) === null));

        if ($missing === []) {
            return;
        }

        $candidateDirs = $this->manifestHintDirectories();
        foreach ($candidateDirs as $dir) {
            foreach ($missing as $relative) {
                $candidate = $dir.'/'.$relative;
                if ($this->downloadRemoteFile($connection, $candidate, $effectiveRoot) === null) {
                    continue;
                }

                $output[] = 'FTP manifest hint: '.$relative.' exists at '.$this->joinRemotePath($effectiveRoot, $candidate).'. Set the project FTP root/path to that directory before running dependency actions.';
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function manifestHintDirectories(): array
    {
        return [
            'public_html',
            'www',
            'htdocs',
            'httpdocs',
            'public',
            'app',
            'site',
        ];
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
        $fileTest = $this->testWrite($connection, $createdPath, $displayPath);
        @ftp_rmdir($connection, $createdPath);
        if ($createdPath !== $directory) {
            @ftp_rmdir($connection, $directory);
        }

        if ($fileTest['status'] !== 'ok') {
            return [
                'status' => 'warning',
                'message' => 'Connected and created a directory, but file uploads failed in the remote path: '.$displayPath.'. Check FTP upload permissions, quota, or passive mode.',
            ];
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

        // For FTP projects, local_path is the remote project subdirectory under
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

                if ($baseRoot === '' && str_starts_with($normalizedProjectPath, '/')) {
                    // Preserve absolute remote paths when no base root is set.
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
     * @param array<int, string> $whitelistPaths
     * @param array{files: int, uploaded: int, skipped: int, directories: int} $stats
     */
    private function syncDirectory(&$connection, string $localPath, string $remoteRoot, array $excludePaths, array $whitelistPaths, array &$stats, array &$output): void
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

            if (! $this->shouldInclude($relative, $whitelistPaths)) {
                continue;
            }

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

            if (! $this->uploadRemoteFile($connection, $remotePath, $item->getPathname())) {
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

        if ($this->uploadRemoteFile($connection, $remotePath, $localPath)) {
            return true;
        }

        try {
            $connection = $this->reconnectSyncConnection($output, $connection);
        } catch (\Throwable $exception) {
            return false;
        }

        $this->ensureRemoteDirectory($connection, dirname($remotePath));
        $this->attemptRemotePermissionFix($connection, dirname($remotePath), $remotePath);

        if ($this->uploadRemoteFile($connection, $remotePath, $localPath)) {
            return true;
        }

        $this->logFailedUploadContext($connection, $remotePath, $output);
        $this->testRemoteDirectoryWritable($connection, dirname($remotePath), $output);

        return false;
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function attemptRemotePermissionFix($connection, string $remoteDir, string $remoteFile): void
    {
        if (function_exists('ftp_chmod')) {
            @ftp_chmod($connection, 0775, $remoteDir);
            @ftp_chmod($connection, 0644, $remoteFile);
        }

        @ftp_delete($connection, $remoteFile);
    }

    /**
     * @param resource|\FTP\Connection $connection
     * @param array<int, string> $output
     */
    private function logFailedUploadContext($connection, string $remotePath, array &$output): void
    {
        $pwd = (string) (@ftp_pwd($connection) ?: '.');
        $dir = dirname($remotePath);
        $candidates = implode(', ', $this->remotePathCandidates($remotePath));
        $directoryListing = @ftp_nlist($connection, $dir);
        $directoryVisible = is_array($directoryListing) ? 'yes' : 'no';

        $output[] = 'FTPS upload diagnostics: cwd='.$pwd.'; target='.$remotePath.'; tried='.$candidates.'; target directory visible='.$directoryVisible.'.';
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
        $success = $this->uploadRemoteFile($connection, $remote, $temp);
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
    private function uploadRemoteFile($connection, string $remotePath, string $localPath): bool
    {
        foreach ($this->remotePathCandidates($remotePath) as $candidate) {
            if (@ftp_put($connection, $candidate, $localPath, FTP_BINARY)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function remotePathCandidates(string $remotePath): array
    {
        $remotePath = $this->normalizeRemotePath($remotePath);
        if ($remotePath === '') {
            return [];
        }

        $candidates = [$remotePath];
        if (! str_starts_with($remotePath, './') && ! str_starts_with($remotePath, '/')) {
            $candidates[] = './'.$remotePath;
        }

        $rootPath = $this->syncContext['rootPath'] ?? '';
        $rootPath = is_string($rootPath) ? $this->normalizeRemotePath($rootPath) : '';
        if ($rootPath !== '' && $rootPath !== '.' && ! str_starts_with($remotePath, '/')) {
            $candidates[] = $this->joinRemotePath($rootPath, $remotePath);
        }

        return array_values(array_unique($candidates));
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

        $syncRoot = $this->restoreSyncRoot($connection);
        $original = $syncRoot ?: @ftp_pwd($connection);
        $remotePath = $syncRoot ? $this->relativeToSyncRoot($remotePath) : ltrim($remotePath, '/');
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
    private function restoreSyncRoot($connection): ?string
    {
        $rootPath = $this->syncContext['rootPath'] ?? '';
        $rootPath = is_string($rootPath) ? $this->normalizeRemotePath($rootPath) : '';
        if ($rootPath === '' || $rootPath === '.') {
            return @ftp_pwd($connection) ?: null;
        }

        if (! @ftp_chdir($connection, $rootPath)) {
            return null;
        }

        return @ftp_pwd($connection) ?: null;
    }

    private function relativeToSyncRoot(string $remotePath): string
    {
        $rootPath = $this->syncContext['rootPath'] ?? '';
        $rootPath = is_string($rootPath) ? $this->normalizeRemotePath($rootPath) : '';
        if ($rootPath === '' || $rootPath === '.') {
            return ltrim($remotePath, '/');
        }

        $relative = ltrim($remotePath, '/');
        $root = ltrim($rootPath, '/');

        if ($relative === $root) {
            return '';
        }

        if (str_starts_with($relative, $root.'/')) {
            return substr($relative, strlen($root) + 1);
        }

        return $relative;
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
     * @return array<int, string>
     */
    private function projectExcludePaths(Project $project): array
    {
        return $this->parsePathList((string) ($project->exclude_paths ?? ''));
    }

    /**
     * @return array<int, string>
     */
    private function projectWhitelistPaths(Project $project): array
    {
        return $this->parsePathList((string) ($project->whitelist_paths ?? ''));
    }

    /**
     * @param array<int, string> $patterns
     * @return array<int, string>
     */
    private function normalizePathPatterns(array $patterns): array
    {
        $normalized = [];
        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            $candidate = trim(str_replace('\\', '/', $pattern));
            if ($candidate === '') {
                continue;
            }

            $normalized[] = ltrim($candidate, '/');
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int, string>
     */
    private function parsePathList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $normalized = str_replace(',', "\n", $normalized);

        $paths = [];
        foreach (explode("\n", $normalized) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $paths[] = str_replace('\\', '/', $line);
        }

        return array_values(array_unique($paths));
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
     * @param array<int, string> $whitelistPaths
     */
    private function shouldInclude(string $relative, array $whitelistPaths): bool
    {
        if ($whitelistPaths === []) {
            return true;
        }

        $relative = ltrim($relative, '/');

        foreach ($whitelistPaths as $pattern) {
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
