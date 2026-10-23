<?php

use App\Http\Controllers\MockInsurerController;
use App\Http\Controllers\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PolicyFlow REST API Contract
|--------------------------------------------------------------------------
*/

Route::prefix('policy-requests')->group(function () {
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
    Route::post('/{id}/submit', [PolicyRequestController::class, 'submit']);
    Route::post('/{id}/reconcile', [PolicyRequestController::class, 'reconcile']);
});

Route::prefix('mock-insurer')->group(function () {
    Route::post('/callback', [MockInsurerController::class, 'handleCallback']);
    Route::get('/policies/{provider_reference}', [MockInsurerController::class, 'lookup']);
});
