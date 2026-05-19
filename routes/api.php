<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\SyncStatusController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\PrinterController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Health check endpoints (public)
Route::get('/health', [HealthController::class, 'index']);
Route::get('/health/detailed', [HealthController::class, 'detailed'])->middleware('edge.api.key');

// Sync status endpoints
Route::prefix('sync')->middleware('edge.api.key')->group(function () {
    Route::get('/status', [SyncStatusController::class, 'index']);
    Route::get('/pending', [SyncStatusController::class, 'pending']);
    Route::get('/logs', [SyncStatusController::class, 'logs']);
    Route::post('/trigger', [SyncStatusController::class, 'trigger']);
});

// Backup management endpoints
Route::prefix('backup')->group(function () {
    Route::post('/trigger', [BackupController::class, 'trigger']);
    Route::get('/list', [BackupController::class, 'list']);
    Route::post('/restore', [BackupController::class, 'restore']);
    Route::get('/status', [BackupController::class, 'status']);
});

// Printer management endpoints
Route::prefix('printers')->group(function () {
    Route::get('/', [PrinterController::class, 'index']);
    Route::get('/{id}', [PrinterController::class, 'show']);
    Route::post('/', [PrinterController::class, 'store']);
    Route::put('/{id}', [PrinterController::class, 'update']);
    Route::delete('/{id}', [PrinterController::class, 'destroy']);
    Route::post('/{id}/check-status', [PrinterController::class, 'checkStatus']);
    Route::post('/print', [PrinterController::class, 'print']);
    Route::get('/queue/status', [PrinterController::class, 'queueStatus']);

    // Browser printing endpoints
    Route::post('/print-on-browser', [PrinterController::class, 'printOnBrowser']);
    Route::post('/print-real-browser', [PrinterController::class, 'printRealBrowser']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
