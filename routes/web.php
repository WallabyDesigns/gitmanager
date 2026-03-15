<?php

use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Livewire\Projects\Create as ProjectsCreate;
use App\Livewire\Projects\Edit as ProjectsEdit;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectsShow;
use App\Livewire\Security\Index as SecurityIndex;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/projects');

Route::post('/webhooks/github', GitHubWebhookController::class)->name('webhooks.github');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/projects', ProjectsIndex::class)->name('projects.index');
    Route::get('/projects/new', ProjectsCreate::class)->name('projects.create');
    Route::get('/projects/{project}/edit', ProjectsEdit::class)->name('projects.edit');
    Route::get('/projects/{project}', ProjectsShow::class)->name('projects.show');
    Route::get('/security', SecurityIndex::class)->name('security.index');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
