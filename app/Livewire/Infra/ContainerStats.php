<?php

declare(strict_types=1);

namespace App\Livewire\Infra;

use App\Services\DockerService;
use Livewire\Component;

class ContainerStats extends Component
{
    public bool  $dockerAvailable = false;
    public array $stats           = [];
    public array $summary         = ['running' => 0, 'total' => 0];

    public function loadStats(): void
    {
        $docker = app(DockerService::class);

        if (! $docker->isAvailable()) {
            return;
        }

        $this->dockerAvailable = true;

        $containers = $docker->listContainers(true);
        $this->summary = [
            'running' => collect($containers)->where('State', 'running')->count(),
            'total'   => count($containers),
        ];

        $this->stats = $docker->getAllContainerStats();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.infra.container-stats');
    }
}
