<?php

use App\Http\Controllers\RecoveryController;
use App\Http\Controllers\SelfUpdateController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Livewire\AppUpdates\Index as AppUpdatesIndex;
use App\Livewire\System\EnvMigration;
use App\Livewire\Projects\Create as ProjectsCreate;
use App\Livewire\Projects\Edit as ProjectsEdit;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Queue as ProjectsQueue;
use App\Livewire\Projects\Scheduler as ProjectsScheduler;
use App\Livewire\Projects\Show as ProjectsShow;
use App\Livewire\FtpAccounts\Index as FtpAccountsIndex;
use App\Livewire\Security\Index as SecurityIndex;
use App\Livewire\System\EmailSettings as SystemEmailSettings;
use App\Livewire\System\Settings as SystemSettings;
use App\Livewire\System\Support as SystemSupport;
use App\Livewire\System\WhiteLabel as SystemWhiteLabel;
use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Workflows\Index as WorkflowsIndex;
use App\Livewire\Infra\Containers as InfraContainers;
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
    Route::get('/recovery', [RecoveryController::class, 'index'])->name('recovery.index');
    Route::post('/rebuild', [RecoveryController::class, 'rebuild'])->name('recovery.rebuild');
    Route::post('/recovery/env-backup', [RecoveryController::class, 'createEnvBackup'])->name('recovery.env-backup.create');
    Route::post('/recovery/env-backup/{filename}/restore', [RecoveryController::class, 'restoreEnvBackup'])->name('recovery.env-backup.restore');
    Route::post('/recovery/env-backup/{filename}/delete', [RecoveryController::class, 'deleteEnvBackup'])->name('recovery.env-backup.delete');

    Route::get('/env/migrate', EnvMigration::class)->name('env.migrate');
    Route::get('/preview/500', function () {
        $exception = new RuntimeException('Preview error: this is a sample message for the 500 page.');

        return response()
            ->view('errors.500', ['exception' => $exception], 500);
    })->name('errors.preview.500');

    Route::middleware(EnsureAdminUser::class)->group(function () {
        Route::get('/update', [SelfUpdateController::class, 'update'])->name('system.update.manual');
        Route::get('/rollback', [SelfUpdateController::class, 'rollback'])->name('system.update.rollback');
        Route::get('/system', AppUpdatesIndex::class)->name('system.updates');
        Route::get('/system/security', SecurityIndex::class)->name('system.security');
        Route::get('/system/settings', function () {
            $section = strtolower(trim((string) request()->query('section', SystemSettings::SECTION_SCHEDULER)));
            $target = match ($section) {
                SystemSettings::SECTION_APPLICATION, SystemSettings::SECTION_REGIONAL => 'system.application',
                SystemSettings::SECTION_AUDITS => 'system.audits',
                SystemSettings::SECTION_LICENSING => 'system.licensing',
                default => 'system.scheduler',
            };

            return redirect()->route($target);
        })->name('system.settings');
        Route::get('/system/scheduler', SystemSettings::class)->name('system.scheduler');
        Route::get('/system/application', SystemSettings::class)->name('system.application');
        Route::get('/system/audits', SystemSettings::class)->name('system.audits');
        Route::get('/system/licensing', SystemSettings::class)->name('system.licensing');
        Route::get('/system/email', SystemEmailSettings::class)->name('system.email');
        Route::get('/system/environment', SystemSettings::class)->name('system.environment');
        Route::get('/system/support', SystemSupport::class)->name('system.support');
        Route::get('/system/white-label', SystemWhiteLabel::class)->name('system.white-label');
        Route::get('/workflows', WorkflowsIndex::class)->name('workflows.index');
        Route::get('/ftp-accounts', FtpAccountsIndex::class)->name('ftp-accounts.index');
    });

    Route::redirect('/app-updates', '/system')->name('app-updates.index');
    Route::redirect('/security', '/system/security')->name('security.index');

    Route::get('/containers', InfraContainers::class)->name('infra.containers');
    Route::redirect('/containers/resources', '/containers', 301);
    Route::get('/containers/{section}', InfraContainers::class)
        ->whereIn('section', [
            'overview', 'containers', 'images', 'volumes', 'networks',
            'swarm', 'databases', 'templates',
            // enterprise sections
            'nodes', 'audits', 'stacks', 'deployments',
            'builds', 'repos', 'procedures', 'actions', 'builders',
            'syncs', 'alerts', 'updates', 'settings',
        ])
        ->name('infra.containers.section');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
