<?php

namespace App\Livewire\Projects;

use App\Models\NodeProcess as NodeProcessModel;
use App\Models\Project;
use App\Services\NodeInstallService;
use App\Services\NodeProcessService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class NodeProcess extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public string $startCommand = 'npm start';

    public ?int $port = null;

    public bool $autoRestart = true;

    public bool $showLog = false;

    public function mount(Project $project): void
    {
        $this->project = $project;
        $process = $this->loadProcess();
        $this->startCommand = $process->start_command;
        $this->port = $process->port;
        $this->autoRestart = $process->auto_restart;
    }

    public function start(NodeProcessService $service): void
    {
        $this->saveSettings($service);
        $process = $this->loadProcess();
        $result = $service->start($process);
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function stop(NodeProcessService $service): void
    {
        $process = $this->loadProcess();
        $result = $service->stop($process);
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function restart(NodeProcessService $service): void
    {
        $this->saveSettings($service);
        $process = $this->loadProcess();
        $result = $service->restart($process);
        $this->dispatch('notify', message: $result['message']);
        $this->dispatch('$refresh');
    }

    public function clearLog(NodeProcessService $service): void
    {
        $process = $this->loadProcess();
        $service->clearLog($process);
        $this->dispatch('notify', message: 'Log cleared.');
        $this->dispatch('$refresh');
    }

    public function saveSettings(NodeProcessService $service): void
    {
        $process = $this->loadProcess();
        $process->forceFill([
            'start_command' => trim($this->startCommand) ?: 'npm start',
            'port' => $this->port > 0 ? $this->port : null,
            'auto_restart' => $this->autoRestart,
        ])->save();
    }

    public function render(NodeProcessService $processService, NodeInstallService $installService): View
    {
        $process = $this->loadProcess();
        $logLines = $this->showLog ? $processService->tailLog($process, 150) : null;

        return view('livewire.projects.node-process', [
            'process' => $process,
            'nodeInstalled' => $installService->isInstalled(),
            'canRunMore' => $processService->canRunMore() || $process->isActive(),
            'freeLimit' => NodeProcessService::FREE_LIMIT,
            'logLines' => $logLines,
        ]);
    }

    private function loadProcess(): NodeProcessModel
    {
        return NodeProcessModel::firstOrCreate(
            ['project_id' => $this->project->id],
            ['status' => NodeProcessModel::STATUS_STOPPED, 'start_command' => 'npm start'],
        );
    }
}
