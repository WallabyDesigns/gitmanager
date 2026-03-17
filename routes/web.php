<?php

use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Livewire\AppUpdates\Index as AppUpdatesIndex;
use App\Livewire\Projects\Create as ProjectsCreate;
use App\Livewire\Projects\Edit as ProjectsEdit;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectsShow;
use App\Livewire\Security\Index as SecurityIndex;
use App\Livewire\Users\Index as UsersIndex;
use App\Http\Middleware\EnsureAdminUser;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/projects');

Route::post('/webhooks/github', GitHubWebhookController::class)->name('webhooks.github');

Route::middleware(['auth', 'verified', EnsurePasswordChanged::class])->group(function () {
    Route::get('/projects', ProjectsIndex::class)->name('projects.index');
    Route::get('/projects/new', ProjectsCreate::class)->name('projects.create');
    Route::get('/projects/{project}/edit', ProjectsEdit::class)->name('projects.edit');
    Route::get('/projects/{project}', ProjectsShow::class)->name('projects.show');
    Route::get('/security', SecurityIndex::class)->name('security.index');
    Route::get('/users', UsersIndex::class)->middleware(EnsureAdminUser::class)->name('users.index');
    Route::get('/app-updates', AppUpdatesIndex::class)->name('app-updates.index');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
