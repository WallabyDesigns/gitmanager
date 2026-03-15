<?php

namespace App\Services;

use App\Models\Project;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RepositoryBootstrapper
{
    /**
     * @return array{status: string, dirty?: bool, output?: array<int, string>}
     */
    public function bootstrap(Project $project): array
    {
        $path = $project->local_path;

        if (! is_dir($path)) {
            throw new \RuntimeException('Project path not found: '.$path);
        }

        if ($this->findGitRoot($path)) {
            return ['status' => 'exists'];
        }

        if (! $project->repo_url) {
            throw new \RuntimeException('Repository URL is required to initialize git for this project.');
        }

        $output = [];
        $branch = $project->default_branch ?: 'main';

        $this->runProcess(['git', 'init'], $output, $path);
        $this->runProcess(['git', 'remote', 'add', 'origin', $project->repo_url], $output, $path);
        $this->runProcess(['git', 'fetch', '--all', '--prune'], $output, $path);
        $this->runProcess(['git', 'checkout', '-b', $branch], $output, $path);
        $this->runProcess(['git', 'reset', '--mixed', 'origin/'.$branch], $output, $path);

        $status = trim($this->runProcess(['git', 'status', '--porcelain'], $output, $path, false)->getOutput());

        return [
            'status' => 'bootstrapped',
            'dirty' => $status !== '',
            'output' => $output,
        ];
    }

    private function findGitRoot(string $path): ?string
    {
        $cursor = $path;

        while (true) {
            if (is_dir($cursor.DIRECTORY_SEPARATOR.'.git')) {
                return $cursor;
            }

            $parent = dirname($cursor);
            if (! $parent || $parent === $cursor) {
                return null;
            }

            $cursor = $parent;
        }
    }

    private function runProcess(array $command, array &$output = [], ?string $workingDir = null, bool $throwOnFailure = true): Process
    {
        $process = new Process($command, $workingDir, [
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output[] = trim($buffer);
        });

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }
}
