<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OidcController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkflowExportController;
use App\Http\Middleware\RememberCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::livewire('invitations/{token}', 'pages::invitations.show')->name('invitations.show');

Route::any('hooks/{key}', WebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.receive');

Route::livewire('forms/{key}', 'pages::forms.show')->name('forms.show');

Route::middleware(['guest', 'throttle:30,1'])->prefix('auth/oidc/{provider}')->name('oidc.')->group(function () {
    Route::get('redirect', [OidcController::class, 'redirect'])->name('redirect');
    Route::get('callback', [OidcController::class, 'callback'])->name('callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::livewire('notifications', 'pages::notifications.index')->name('notifications');

    Route::prefix('w/{workspace}')
        ->name('workspaces.')
        ->middleware(RememberCurrentWorkspace::class)
        ->scopeBindings()
        ->group(function () {
            Route::livewire('/', 'pages::workspaces.overview')
                ->middleware('can:view-own-assignments,workspace')->name('show');
            Route::livewire('workflows', 'pages::workflows.index')
                ->middleware('can:view-workflows,workspace')->name('workflows');
            Route::livewire('workflows/{workflow}', 'pages::workflows.edit')
                ->middleware('can:view-workflows,workspace')->name('workflows.edit');
            Route::get('workflows/{workflow}/export', WorkflowExportController::class)
                ->middleware('can:view-workflows,workspace')->name('workflows.export');
            Route::livewire('runs', 'pages::executions.index')
                ->middleware('can:view-executions,workspace')->name('executions');
            Route::livewire('runs/{execution}', 'pages::executions.show')
                ->middleware('can:view-executions,workspace')->name('executions.show');
            Route::livewire('inbox', 'pages::inbox.index')
                ->middleware('can:view-own-assignments,workspace')->name('inbox');
            Route::livewire('inbox/{assignment}', 'pages::inbox.show')
                ->middleware('can:view-own-assignments,workspace')->name('inbox.show');
            Route::livewire('variables', 'pages::workspaces.variables')
                ->middleware('can:view-variables,workspace')->name('variables');
            Route::livewire('secrets', 'pages::workspaces.secrets')
                ->middleware('can:view-secrets,workspace')->name('secrets');
            Route::livewire('authentication', 'pages::workspaces.credentials')
                ->middleware('can:view-credentials,workspace')->name('credentials');
            Route::livewire('members', 'pages::workspaces.members')
                ->middleware('can:view-members,workspace')->name('members');
            Route::livewire('settings', 'pages::workspaces.settings')
                ->middleware('can:manage-settings,workspace')->name('settings');
            Route::livewire('audit', 'pages::workspaces.audit')
                ->middleware('can:view-audit-log,workspace')->name('audit');
        });

    Route::prefix('admin')
        ->name('admin.')
        ->middleware('can:org-admin')
        ->group(function () {
            Route::livewire('workspaces', 'pages::admin.workspaces')->name('workspaces');
            Route::livewire('users', 'pages::admin.users')->name('users');
            Route::livewire('settings', 'pages::admin.settings')->name('settings');
            Route::livewire('sign-in-providers', 'pages::admin.sign-in-providers')->name('sign-in-providers');
            Route::livewire('email', 'pages::admin.email')->name('email');
            Route::livewire('audit', 'pages::admin.audit')->name('audit');
        });
});

require __DIR__.'/settings.php';
