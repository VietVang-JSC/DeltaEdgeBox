<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\SyncStatusController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentPrintController;
use App\Http\Controllers\MasterSyncController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\KitchenPrintController;
use App\Http\Controllers\Api\PosWebFilterController;

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
// Login endpoint (public)
Route::post('/edge/login', [UserController::class, 'loginWeb']);

// Health check endpoints (public)
Route::get('/health', [HealthController::class, 'index']);
Route::get('/health/detailed', [HealthController::class, 'detailed'])->middleware('edge.api.key');

// Sync status endpoints
Route::prefix('sync')->middleware('edge.api.key')->group(function () {
    Route::get('/status', [SyncStatusController::class, 'index']);
    Route::get('/pending', [SyncStatusController::class, 'pending']);
    Route::get('/queue', [SyncStatusController::class, 'queue']);
    Route::get('/logs', [SyncStatusController::class, 'logs']);
    Route::post('/trigger', [SyncStatusController::class, 'trigger']);
});


// Master data sync endpoint
Route::prefix('edge')
    ->middleware('edge.api.key')
    ->group(function () {
        Route::post('/master-sync', [MasterSyncController::class, 'sync']);
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

// Legacy POS payment compatibility endpoints
Route::prefix('user/payment')->middleware('edge.api.key')->group(function () {
    Route::post('/create_payment', [PaymentController::class, 'createPayment']);
    Route::post('/update_payment', [PaymentController::class, 'updatePayment']);
});

Route::prefix('payment')->middleware('edge.api.key')->group(function () {
    Route::get('/print-for-web', [PaymentPrintController::class, 'printForWeb']);
});

Route::prefix('admin/payment')->middleware('edge.api.key')->group(function () {
    Route::get('/print_payment', [PaymentPrintController::class, 'printPayment']);
});


// Inventory management endpoints 
Route::prefix('inventory')->middleware('edge.api.key')->group(function () {
    Route::get('/',           [InventoryController::class, 'index']);
    Route::get('/low-stock',  [InventoryController::class, 'lowStock']);
    Route::get('/history',    [InventoryController::class, 'history']);
    Route::post('/adjust',    [InventoryController::class, 'adjust']);
    Route::post('/restock',   [InventoryController::class, 'restock']);
});

// Legacy POS table compatibility endpoints
Route::prefix('user/table')->middleware('edge.api.key')->group(function () {
    Route::get('/list', [TableController::class, 'index']);
    Route::get('/get_table/{id?}', [TableController::class, 'show']);
    Route::post('/check_in_table', [TableController::class, 'checkIn']);
    Route::post('/check_out_table_new', [TableController::class, 'checkOut']);
    Route::post('/update_order_table', [TableController::class, 'updateOrder']);
    Route::post('/change-table', [TableController::class, 'changeTable']);
    Route::post('/check-payment-printed', [KitchenPrintController::class, 'checkPrintedStatus']);
    Route::get('/kitchen/print-all', [KitchenPrintController::class, 'printAll']);
    Route::get('/kitchen/print-next-web', [KitchenPrintController::class, 'printNextWeb']);
    Route::get('/kitchen/print-on-browser', [KitchenPrintController::class, 'printOnBrowser']);
});

Route::prefix('posWeb')->middleware('edge.api.key')->group(function () {
    Route::post('/filter', [PosWebFilterController::class, 'filter']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
