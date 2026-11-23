<?php

use App\Http\Controllers\Api\RunController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * The REST API, under /api/v1. Requests authenticate with a personal access
 * token as a bearer token; each route checks the token's scope and the
 * person's workspace role in App\Services\WorkflowApi.
 */
Route::middleware(['auth:api', 'throttle:120,1'])->name('api.')->group(function () {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');

    Route::prefix('workspaces/{workspace}')->name('workspaces.')->group(function () {
        Route::get('workflows', [WorkflowController::class, 'index'])->name('workflows.index');
        Route::get('workflows/{workflow}', [WorkflowController::class, 'show'])->name('workflows.show');
        Route::post('workflows/{workflow}/runs', [RunController::class, 'store'])->name('runs.store');
        Route::get('runs', [RunController::class, 'index'])->name('runs.index');
        Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');
        Route::post('runs/{run}/cancel', [RunController::class, 'cancel'])->name('runs.cancel');
    });
});
