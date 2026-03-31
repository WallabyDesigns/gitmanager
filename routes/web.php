<?php

use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Controllers\SelfUpdateController;
use App\Livewire\AppUpdates\Index as AppUpdatesIndex;
use App\Livewire\Projects\Create as ProjectsCreate;
use App\Livewire\Projects\Edit as ProjectsEdit;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Queue as ProjectsQueue;
use App\Livewire\Projects\Scheduler as ProjectsScheduler;
use App\Livewire\Projects\Show as ProjectsShow;
use App\Livewire\FtpAccounts\Index as FtpAccountsIndex;
use App\Livewire\Security\Index as SecurityIndex;
use App\Livewire\System\EmailSettings as SystemEmailSettings;
use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Workflows\Index as WorkflowsIndex;
use App\Http\Middleware\EnsureAdminUser;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! User::query()->exists()) {
        return redirect()->route('register');
    }

    if (Auth::check()) {
        return redirect()->route('projects.index');
    }

    return redirect()->route('login');
});

Route::post('/webhooks/github', GitHubWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.github');

Route::middleware(['auth', 'verified', EnsurePasswordChanged::class])->group(function () {
    Route::get('/projects', ProjectsIndex::class)->name('projects.index');
    Route::get('/projects/queue', ProjectsQueue::class)->name('projects.queue');
    Route::get('/projects/scheduler', ProjectsScheduler::class)->name('projects.scheduler');
    Route::get('/projects/new', ProjectsCreate::class)->name('projects.create');
    Route::get('/projects/{project}/edit', ProjectsEdit::class)->name('projects.edit');
    Route::get('/projects/{project}', ProjectsShow::class)->name('projects.show');
    Route::get('/users', UsersIndex::class)->middleware(EnsureAdminUser::class)->name('users.index');
    Route::get('/update', [SelfUpdateController::class, 'update'])->name('system.update.manual');
    Route::get('/rollback', [SelfUpdateController::class, 'rollback'])->name('system.update.rollback');

    Route::middleware(EnsureAdminUser::class)->group(function () {
        Route::get('/system', AppUpdatesIndex::class)->name('system.updates');
        Route::get('/system/security', SecurityIndex::class)->name('system.security');
        Route::get('/system/email', SystemEmailSettings::class)->name('system.email');
        Route::get('/workflows', WorkflowsIndex::class)->name('workflows.index');
        Route::get('/ftp-accounts', FtpAccountsIndex::class)->name('ftp-accounts.index');
    });

    Route::redirect('/app-updates', '/system')->name('app-updates.index');
    Route::redirect('/security', '/system/security')->name('security.index');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
