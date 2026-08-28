<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class SelfUpdatePruneBackupsCommandTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $createdDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->createdDirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public function test_prune_backups_command_removes_old_entries_beyond_retention(): void
    {
        $base = storage_path('app/self-update-backups');
        $preExisting = count(glob($base.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: []);

        // Keep everything that was already there, plus exactly one of the
        // two entries this test adds.
        config(['gitmanager.self_update.backup_retention' => $preExisting + 1]);

        $stale = $this->makeBackupDir($base, 'test-stale-'.Str::uuid(), -1_000_000);
        $recent = $this->makeBackupDir($base, 'test-recent-'.Str::uuid(), -1);

        $this->artisan('self-update:prune-backups')
            ->expectsOutputToContain(sprintf('removed 1, keeping %d', $preExisting + 1))
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($recent);
    }

    public function test_prune_backups_command_dry_run_does_not_delete(): void
    {
        $base = storage_path('app/self-update-untracked');
        $preExisting = count(glob($base.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: []);

        config(['gitmanager.self_update.backup_retention' => $preExisting + 1]);

        $older = $this->makeBackupDir($base, 'test-older-'.Str::uuid(), -1_000_000);
        $newer = $this->makeBackupDir($base, 'test-newer-'.Str::uuid(), -1);

        $this->artisan('self-update:prune-backups --dry-run')
            ->expectsOutputToContain(sprintf('would remove 1, keeping %d', $preExisting + 1))
            ->assertSuccessful();

        $this->assertDirectoryExists($older);
        $this->assertDirectoryExists($newer);
    }

    private function makeBackupDir(string $base, string $name, int $mtimeOffsetSeconds): string
    {
        $path = $base.DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists($path);
        touch($path, time() + $mtimeOffsetSeconds);
        $this->createdDirs[] = $path;

        return $path;
    }
}
