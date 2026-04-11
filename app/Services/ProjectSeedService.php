<?php

namespace App\Services;

use App\Models\Project;

class ProjectSeedService
{
    public function seedPath(Project $project, string $filename): string
    {
        return storage_path('app/project-seeds/'.$project->id.'/'.ltrim($filename, '/'));
    }

    public function hasSeed(Project $project, string $filename): bool
    {
        return is_file($this->seedPath($project, $filename));
    }

    public function store(Project $project, string $filename, string $content): bool
    {
        $path = $this->seedPath($project, $filename);
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return false;
        }

        if (@file_put_contents($path, $content) === false) {
            return false;
        }

        @chmod($path, 0664);

        return true;
    }

    /**
     * @param array<int, string> $output
     */
    public function applyIfMissing(Project $project, string $filename, string $targetRoot, array &$output): bool
    {
        $targetRoot = rtrim($targetRoot, DIRECTORY_SEPARATOR);
        if ($targetRoot === '' || ! is_dir($targetRoot)) {
            return false;
        }

        $targetPath = $targetRoot.DIRECTORY_SEPARATOR.ltrim($filename, '/');
        if (is_file($targetPath)) {
            return false;
        }

        $seedPath = $this->seedPath($project, $filename);
        if (! is_file($seedPath)) {
            return false;
        }

        if (! is_writable($targetRoot)) {
            @chmod($targetRoot, 0775);
        }

        if (! is_writable($targetRoot)) {
            $output[] = 'Unable to apply '.$filename.' seed: target directory is not writable.';
            return false;
        }

        if (@copy($seedPath, $targetPath) === false) {
            $output[] = 'Unable to apply '.$filename.' seed: failed to copy.';
            return false;
        }

        @chmod($targetPath, 0664);
        $output[] = 'Applied '.$filename.' seed from setup configuration.';

        return true;
    }
}
