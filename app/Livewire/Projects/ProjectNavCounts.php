<?php

namespace App\Livewire\Projects;

use App\Services\NavigationStateService;
use Livewire\Component;

class ProjectNavCounts extends Component
{
    public function render()
    {
        $state = app(NavigationStateService::class)->projectsSidebarState(auth()->user());

        $this->dispatch('nav-counts-updated', $state);

        return <<<'HTML'
            <div class="hidden"></div>
        HTML;
    }
}