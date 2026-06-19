<?php

namespace App\Livewire\Projects;

use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Services\DeploymentQueueService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class PackageEditor extends Component
{
    use AuthorizesRequests;

    public Project $project;

    /** @var array<int, string> */
    public array $availableFiles = [];

    public string $selectedFile = '';

    public string $fileContent = '';

    public ?int $queueItemId = null;

    public ?string $error = null;

    public ?string $success = null;

    public ?string $installOutput = null;

    private const TRACKED_FILES = [
        'package.json',
        'composer.json',
    ];

    private const INSTALL_ACTIONS = [
        'package.json' => 'npm_install',
        'composer.json' => 'composer_install',
    ];

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->detectFiles();
        if ($this->availableFiles !== []) {
            $this->selectedFile = $this->availableFiles[0];
            $this->loadFile();
        }
    }

    public function render()
    {
        return view('livewire.projects.package-editor');
    }

    public function selectFile(string $filename): void
    {
        if (! in_array($filename, $this->availableFiles, true)) {
            return;
        }
        $this->selectedFile = $filename;
        $this->error = null;
        $this->success = null;
        $this->installOutput = null;
        $this->loadFile();
    }

    public function save(): void
    {
        $this->authorize('update', $this->project);
        $this->error = null;
        $this->success = null;
        $this->installOutput = null;
        $this->queueItemId = null;

        if ($this->selectedFile === '' || ! in_array($this->selectedFile, $this->availableFiles, true)) {
            $this->error = 'No file selected.';
            return;
        }

        $path = $this->resolveFilePath($this->selectedFile);
        if ($path === null) {
            $this->error = 'File path could not be resolved.';
            return;
        }

        $decoded = json_decode($this->fileContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = 'Invalid JSON: '.json_last_error_msg();
            return;
        }

        $normalized = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($normalized === false) {
            $this->error = 'Failed to re-encode JSON.';
            return;
        }

        $originalContent = is_file($path) ? file_get_contents($path) : '';
        if ($originalContent === false) {
            $this->error = 'Could not read existing file for backup.';
            return;
        }

        if (rtrim($originalContent) === rtrim($normalized)) {
            $this->success = 'No changes detected.';
            return;
        }

        $this->writeBackup($originalContent);

        if (file_put_contents($path, $normalized."\n") === false) {
            $this->error = 'Failed to write file. Check server permissions.';
            return;
        }

        $this->fileContent = $normalized."\n";

        $action = self::INSTALL_ACTIONS[$this->selectedFile] ?? null;
        if ($action === null) {
            $this->success = 'File saved.';
            return;
        }

        $item = app(DeploymentQueueService::class)->enqueue(
            $this->project,
            $action,
            ['reason' => 'package_editor_save'],
            Auth::user()
        );

        $this->queueItemId = $item->id;
        $this->success = 'File saved. Installing…';
    }

    public function checkInstall(): void
    {
        if ($this->queueItemId === null) {
            return;
        }

        $item = DeploymentQueueItem::find($this->queueItemId);

        if (! $item || ! in_array($item->status, ['completed', 'failed'], true)) {
            return;
        }

        $this->queueItemId = null;

        if ($item->status === 'failed') {
            $output = $item->deployment?->output ?? 'No output available.';
            $this->installOutput = is_string($output) ? $output : json_encode($output);
            $this->restoreBackup();
            $this->error = 'Install failed — file has been reverted to its previous state.';
            $this->success = null;
            return;
        }

        $this->clearBackup();
        $this->success = 'File saved and install completed successfully.';
        $this->error = null;
    }

    private function detectFiles(): void
    {
        $root = $this->projectRoot();
        if ($root === null) {
            return;
        }

        $found = [];
        foreach (self::TRACKED_FILES as $filename) {
            if (is_file($root.DIRECTORY_SEPARATOR.$filename)) {
                $found[] = $filename;
            }
        }

        $this->availableFiles = $found;
    }

    private function loadFile(): void
    {
        $path = $this->resolveFilePath($this->selectedFile);
        if ($path === null || ! is_file($path)) {
            $this->fileContent = '';
            return;
        }

        $raw = file_get_contents($path);
        $this->fileContent = $raw !== false ? $raw : '';
    }

    private function resolveFilePath(string $filename): ?string
    {
        $root = $this->projectRoot();
        if ($root === null) {
            return null;
        }
        return $root.DIRECTORY_SEPARATOR.$filename;
    }

    private function projectRoot(): ?string
    {
        $path = trim((string) ($this->project->local_path ?? ''));
        if ($path === '' || ! is_dir($path)) {
            return null;
        }
        return $path;
    }

    private function backupKey(): string
    {
        return 'package_backups/'.$this->project->id.'_'.str_replace(['/','\\'], '_', $this->selectedFile);
    }

    private function writeBackup(string $content): void
    {
        Storage::put($this->backupKey(), $content);
    }

    private function restoreBackup(): void
    {
        $key = $this->backupKey();
        if (! Storage::exists($key)) {
            return;
        }

        $content = Storage::get($key);
        $path = $this->resolveFilePath($this->selectedFile);
        if ($path !== null && is_string($content)) {
            file_put_contents($path, $content);
            $this->fileContent = $content;
        }

        Storage::delete($key);
    }

    private function clearBackup(): void
    {
        $key = $this->backupKey();
        if (Storage::exists($key)) {
            Storage::delete($key);
        }
    }
}
