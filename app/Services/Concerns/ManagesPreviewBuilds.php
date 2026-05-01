<?php

namespace App\Services\Concerns;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

trait ManagesPreviewBuilds
{
    public function previewBuild(Project $project, ?User $user = null, string $commit = ''): Deployment
    {
        $repoPath = $this->resolveRepoPath($project);

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'triggered_by' => $user?->id,
            'action' => 'preview_build',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->beginDeploymentStream($deployment);

        $output = [];
        $attempts = 0;

        try {
            while ($attempts < 2) {
                $attempts++;

                try {
                    $this->runProcess(['git', '-C', $repoPath, 'fetch', '--all', '--prune'], $output);

                    $target = trim($commit) !== '' ? trim($commit) : 'origin/'.$project->default_branch;
                    $hash = trim($this->runProcess(['git', '-C', $repoPath, 'rev-parse', $target], $output)->getOutput());
                    $short = substr($hash, 0, 7);

                    $slug = Str::slug($project->name) ?: 'project';
                    $basePath = rtrim((string) config('gitmanager.preview.path', storage_path('app/previews')), DIRECTORY_SEPARATOR);
                    $previewPath = $basePath.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.$short;

                    $this->ensurePath(dirname($previewPath));

                    if (is_dir($previewPath)) {
                        $this->runProcess(['git', '-C', $repoPath, 'worktree', 'remove', '--force', $previewPath], $output, null, false);
                        $this->deleteDirectory($previewPath);
                    }

                    $this->runProcess(['git', '-C', $repoPath, 'worktree', 'add', '--force', $previewPath, $hash], $output);
                    $output[] = 'Preview path: '.$previewPath;

                    $baseUrl = trim((string) config('gitmanager.preview.base_url', ''));
                    if ($baseUrl !== '') {
                        $output[] = 'Preview url: '.rtrim($baseUrl, '/').'/'.$slug.'/'.$short;
                    }

                    $this->runWithSingleRetry(function () use ($project, $previewPath, &$output): void {
                        if ($project->run_composer_install && is_file($previewPath.DIRECTORY_SEPARATOR.'composer.json')) {
                            $this->runComposerCommandWithFallback(
                                $previewPath,
                                $output,
                                'Composer install',
                                ['composer', 'install', '--no-dev', '--optimize-autoloader']
                            );
                        }

                        if ($project->run_npm_install && is_file($previewPath.DIRECTORY_SEPARATOR.'package.json')) {
                            $this->runNpmInstallWithFallback(
                                $previewPath,
                                $output,
                                'Npm install',
                                $this->npmInstallCommand($previewPath)
                            );
                        }

                        if ($project->run_build_command && $project->build_command) {
                            $this->runBuildCommandWithNpmRecovery($project, $previewPath, $output, 'Preview build command');
                        }

                        if ($project->run_test_command && $project->test_command) {
                            $this->runTestCommand($project, $project->test_command, $previewPath, $output);
                        }
                    }, $output, 'Preview build tasks');

                    $deployment->status = 'success';
                    $this->appendWorkflowOutput($deployment, $project, $output);
                    $deployment->output_log = implode("\n", $output);
                    $deployment->finished_at = now();
                    $deployment->save();

                    return $deployment;
                } catch (\Throwable $exception) {
                    if ($attempts < 2) {
                        $output[] = 'Preview build failed. Retrying once.';

                        continue;
                    }

                    $deployment->status = 'failed';
                    $this->appendWorkflowOutput($deployment, $project, $output);
                    $deployment->output_log = trim(implode("\n", $output)."\n".$exception->getMessage());
                    $deployment->finished_at = now();
                    $deployment->save();
                }
            }

            return $deployment;
        } finally {
            $this->endDeploymentStream();
        }
    }
}
