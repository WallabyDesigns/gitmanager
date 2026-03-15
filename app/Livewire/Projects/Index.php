<?php

namespace App\Livewire\Projects;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('livewire.projects.index', [
            'projects' => Auth::user()
                ->projects()
                ->latest()
                ->get(),
        ])->layout('layouts.app', [
            'header' => view('livewire.projects.partials.index-header'),
        ]);
    }

}
