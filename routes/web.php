<?php

use App\Http\Controllers\DashboardController;
use App\Http\Middleware\RememberCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('invitations/{token}', 'pages::invitations.show')->name('invitations.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('w/{workspace}')
        ->name('workspaces.')
        ->middleware(RememberCurrentWorkspace::class)
        ->group(function () {
            Route::livewire('/', 'pages::workspaces.overview')
                ->middleware('can:view-own-assignments,workspace')->name('show');
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
        ->middleware('can:install-admin')
        ->group(function () {
            Route::livewire('workspaces', 'pages::admin.workspaces')->name('workspaces');
            Route::livewire('users', 'pages::admin.users')->name('users');
            Route::livewire('settings', 'pages::admin.settings')->name('settings');
            Route::livewire('audit', 'pages::admin.audit')->name('audit');
        });
});

require __DIR__.'/settings.php';
