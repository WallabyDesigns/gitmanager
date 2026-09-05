<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class LarustPlugin implements ManagedPlugin
{
    public const REPOSITORY = 'https://github.com/WallabyDesigns/Larust';

    public function slug(): string
    {
        return 'larust';
    }

    public function displayName(): string
    {
        return 'Larust CLI';
    }

    public function description(): string
    {
        return 'Install the Larust xr CLI from GitHub for local deployments. Requires Rust/Cargo and a native compiler on this host. SSH hosts need a separate installation.';
    }

    public function category(): string
    {
        return 'runtime';
    }

    public function installDir(): string
    {
        return storage_path('plugins/larust');
    }

    public function binary(): string
    {
        return $this->installDir().DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'xr.exe' : 'xr');
    }

    public function isInstalled(): bool
    {
        return is_file($this->binary()) && is_executable($this->binary());
    }

    public function installedVersion(): ?string
    {
        if (! $this->isInstalled()) {
            return null;
        }

        $revision = @file_get_contents($this->installDir().'/revision');

        return is_string($revision) && preg_match('/^[a-f0-9]{40}$/', trim($revision)) ? trim($revision) : null;
    }

    public function fetchLatestVersion(): ?string
    {
        try {
            $response = Http::timeout(15)->acceptJson()->get('https://api.github.com/repos/WallabyDesigns/Larust/commits/HEAD');
            $revision = $response->json('sha');

            return $response->successful() && is_string($revision) && preg_match('/^[a-f0-9]{40}$/', $revision) ? $revision : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Include the managed CLI and the service user's rustup installation. */
    public function environment(array $env): array
    {
        $key = array_key_exists('PATH', $env) ? 'PATH' : (array_key_exists('Path', $env) ? 'Path' : 'PATH');
        $paths = [];
        if ($this->isInstalled()) {
            $paths[] = dirname($this->binary());
        }
        $cargoHome = $env['CARGO_HOME'] ?? '';
        if ($cargoHome === '') {
            $home = $env['HOME'] ?? $env['USERPROFILE'] ?? '';
            $cargoHome = $home !== '' ? $home.DIRECTORY_SEPARATOR.'.cargo' : '';
        }
        if ($cargoHome !== '' && is_dir($cargoHome.DIRECTORY_SEPARATOR.'bin')) {
            $paths[] = $cargoHome.DIRECTORY_SEPARATOR.'bin';
        }
        $paths[] = $env[$key] ?? '';
        $env[$key] = implode(PATH_SEPARATOR, $paths);

        return $env;
    }

    public function install(): array
    {
        $lock = Cache::lock('plugins:larust:mutation', 3900);
        if (! $lock->get()) {
            return ['success' => false, 'message' => 'A Larust install, update, or uninstall is already running.'];
        }

        try {
            // Cargo compilation can exceed PHP's default request limit.
            @set_time_limit(0);
            $env = getenv();
            $env = $this->environment(is_array($env) ? $env : []);
            $key = array_key_exists('PATH', $env) ? 'PATH' : 'Path';
            $extra = trim((string) config('gitmanager.process_path', ''), "\"' ");
            if ($extra !== '') {
                $env[$key] = $extra.PATH_SEPARATOR.($env[$key] ?? '');
            }
            $cargo = Process::env($env)->timeout(15)->run(['cargo', '--version']);
            if (! $cargo->successful()) {
                return ['success' => false, 'message' => 'Rust/Cargo is unavailable. Install Rust for the web server user and add its bin directory to GWM_PROCESS_PATH, then retry.'];
            }
            $revision = $this->fetchLatestVersion();
            if ($revision === null) {
                return ['success' => false, 'message' => 'Could not resolve the latest Larust revision from GitHub.'];
            }
            File::ensureDirectoryExists($this->installDir());
            $result = Process::env($env)->timeout(3600)->run([
                'cargo', 'install', '--git', self::REPOSITORY, '--rev', $revision,
                '--locked', '--root', $this->installDir(), '--force', 'larust-cli',
            ]);
            if (! $result->successful()) {
                return ['success' => false, 'message' => 'Larust build failed: '.substr(trim($result->errorOutput()."\n".$result->output()), -8000)];
            }
            $probe = Process::env($env)->timeout(15)->run([$this->binary(), '--version']);
            if (! $this->isInstalled() || ! $probe->successful()) {
                return ['success' => false, 'message' => 'Larust compiled, but the installed xr binary could not run: '.$probe->errorOutput()];
            }
            if (file_put_contents($this->installDir().'/revision', $revision) === false) {
                throw new \RuntimeException('Could not record the installed Larust revision.');
            }

            return ['success' => true, 'message' => 'Larust installed. The xr CLI is available to local deployment commands.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Larust install failed: '.$e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    public function update(): array
    {
        return $this->install();
    }

    public function uninstall(): array
    {
        $lock = Cache::lock('plugins:larust:mutation', 3900);
        if (! $lock->get()) {
            return ['success' => false, 'message' => 'A Larust install, update, or uninstall is already running.'];
        }
        try {
            if (is_link($this->installDir())) {
                throw new \RuntimeException('Refusing to remove a linked Larust install directory.');
            }
            if (is_dir($this->installDir()) && ! File::deleteDirectory($this->installDir())) {
                throw new \RuntimeException('Could not remove the managed Larust directory.');
            }

            return ['success' => true, 'message' => 'Managed Larust CLI removed. Rust and application files were kept.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    public function checkVulnerabilities(): array
    {
        return $this->isInstalled() ? ['Automatic vulnerability scanning is not available for Larust. Run cargo audit in your application workspace.'] : [];
    }
}
