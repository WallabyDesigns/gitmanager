<?php

namespace App\Livewire\Projects;

use App\Services\NavigationStateService;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class ProjectNavCounts extends Component
{
    public function placeholder(): string
    {
        return '<div class="hidden"></div>';
    }

    public function render()
    {
        $state = app(NavigationStateService::class)->projectsSidebarState(auth()->user());

        $this->dispatch('nav-counts-updated', $state);

        return <<<'HTML'
            <div class="hidden"></div>
        HTML;
    }
}
