<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\SyncStatusController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentPrintController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\MasterSyncController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\KitchenPrintController;
use App\Http\Controllers\Api\PosWebFilterController;
use App\Http\Controllers\Api\SplitMergeInvoiceController;
use App\Http\Controllers\Api\InventoryApiController;
use App\Http\Controllers\Api\InventoryInputController;
use App\Http\Controllers\Api\InventoryOutputController;

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

    // Edge Sync Actions (Retry/Dismiss/Resolve) – throttle 30 req/min
    Route::post('/retry-item', [SyncStatusController::class, 'retryQueueItem'])->middleware('throttle:60,1');
    Route::post('/prioritize-item', [SyncStatusController::class, 'prioritizeQueueItem'])->middleware('throttle:60,1');
    Route::post('/dismiss-item', [SyncStatusController::class, 'dismissFailedItem'])->middleware('throttle:60,1');
    Route::post('/resolve-conflict', [SyncStatusController::class, 'resolveConflict'])->middleware('throttle:60,1');
});


// Master data sync endpoint
Route::prefix('edge')
    ->middleware('edge.api.key')
    ->group(function () {
        Route::post('/master-sync', [MasterSyncController::class, 'sync']);
        Route::get('/last-master-sync', [MasterSyncController::class, 'lastMasterSync']);
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
    Route::post('/get_sale_today', [PaymentController::class, 'getSaleToday']);
    Route::post('/list_open', [PaymentController::class, 'listOpen']);
});
// View payment details for POS web
Route::prefix('edge')->middleware('edge.api.key')->group(function () {
    Route::post('/list-order-new', [PosWebFilterController::class, 'apiEdgeFilterByCondition']);
    
});


Route::prefix('payment')->middleware('edge.api.key')->group(function () {
    Route::get('/print-for-web', [PaymentPrintController::class, 'printForWeb']);
});

Route::prefix('admin/payment')->middleware('edge.api.key')->group(function () {
    Route::get('/print_payment', [PaymentPrintController::class, 'printPayment']);
    Route::post('/delete-payment-detail', [PaymentController::class, 'deletePaymentDetail']);
});


// Inventory management endpoints 
Route::prefix('inventory')->middleware('edge.api.key')->group(function () {
    Route::get('/',           [InventoryController::class, 'index']);
    Route::get('/low-stock',  [InventoryController::class, 'lowStock']);
    Route::get('/history',    [InventoryController::class, 'history']);
    Route::post('/adjust',    [InventoryController::class, 'adjust']);
    Route::post('/restock',   [InventoryController::class, 'restock']);
});

// Cloud-compatible revenue API endpoints
Route::prefix('admin/revenue')->middleware('edge.api.key')->group(function () {
    Route::post('/get_revenue', [PaymentController::class, 'getRevenueToDayByAdminId']);
    Route::post('/get_revenue_by_date', [PaymentController::class, 'getRevenueByDate']);
    Route::post('/get_revenue_by_date_to_date', [PaymentController::class, 'getRevenueByDateToDate']);
});

// Cloud-compatible inventory API endpoints (warehouse UI)
Route::prefix('admin/inventory')->middleware('edge.api.key')->group(function () {
    Route::get('/get_inventory_list', [InventoryApiController::class, 'getInventoryList']);
    Route::get('/get_inventory_detail_by_product_id', [InventoryApiController::class, 'getInventoryDetailByProductId']);
    Route::get('/getInventoryCheckList', [InventoryApiController::class, 'getInventoryCheckList']);
    Route::get('/getInventoryCheckDetail', [InventoryApiController::class, 'getInventoryCheckDetail']);
    Route::post('/checkInventoryStore', [InventoryApiController::class, 'checkInventoryStore']);
    Route::post('/updateInventoryCheck', [InventoryApiController::class, 'updateInventoryCheck']);
    Route::delete('/deleteInventoryCheck', [InventoryApiController::class, 'deleteInventoryCheck']);
});

// Warehouse check-in (input) endpoints
Route::prefix('admin/input')->middleware('edge.api.key')->group(function () {
    Route::get('/getListInvoiceInput', [InventoryInputController::class, 'getListInvoiceInput']);
    Route::post('/getDetailInvoiceInput', [InventoryInputController::class, 'getDetailInvoiceInput']);
    Route::post('/addInvoiceInput', [InventoryInputController::class, 'addInvoiceInput']);
    Route::post('/updateInvoiceInput', [InventoryInputController::class, 'updateInvoiceInput']);
    Route::post('/deleteInvoiceInput', [InventoryInputController::class, 'deleteInvoiceInput']);
});

// Warehouse checkout (output) endpoints
Route::prefix('admin/output')->middleware('edge.api.key')->group(function () {
    Route::get('/exportInvoiceList', [InventoryOutputController::class, 'exportInvoiceList']);
    Route::get('/getDetailInvoiceOutput', [InventoryOutputController::class, 'getDetailInvoiceOutput']);
    Route::post('/create_export_invoice', [InventoryOutputController::class, 'createExportInvoice']);
    Route::post('/updateInvoiceOuput', [InventoryOutputController::class, 'updateInvoiceOutput']);
    Route::delete('/deleteInvoiceOuput', [InventoryOutputController::class, 'deleteInvoiceOutput']);
});

// Legacy POS table compatibility endpoints
Route::prefix('user/table')->middleware('edge.api.key')->group(function () {
    Route::get('/list', [TableController::class, 'index']);
    Route::match(['GET', 'POST'], '/get_table/{id?}', [TableController::class, 'show']);
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

Route::prefix('admin/product')->middleware('edge.api.key')->group(function () {
    Route::post('/search', [PosWebFilterController::class, 'searchProducts']);
});

Route::prefix('user/payment_detail')->middleware('edge.api.key')->group(function () {
    Route::get('/getServedStatus', [TableController::class, 'getServedStatus']);
    Route::post('/served', [TableController::class, 'served']);
});

Route::prefix('common/payment-status')->middleware('edge.api.key')->group(function () {
    Route::get('/get-all', [TableController::class, 'getPaymentMethods']);
});

Route::prefix('user/split-merge-invoice')->middleware('edge.api.key')->group(function () {
    Route::get('/get-list-invoice', [SplitMergeInvoiceController::class, 'getListInvoice']);
    Route::post('/split-invoice', [SplitMergeInvoiceController::class, 'splitInvoice']);
    Route::post('/merge-invoice', [SplitMergeInvoiceController::class, 'mergeInvoice']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
