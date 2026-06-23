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
use App\Http\Controllers\ApiEdgeController;

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

Route::get('/edge/cleanup', function () {
    try {
        DB::statement('DELETE FROM payment_details');
        DB::statement('DELETE FROM payments');
        DB::statement('DELETE FROM sync_queues');
        DB::statement('DELETE FROM sync_logs');
        DB::statement('DELETE FROM sync_metadata');
        return response()->json(['status' => true, 'message' => 'Cleared']);
    } catch (\Throwable $e) {
        return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
    }
});

//get all master data for edge sync
Route::get('/edge/filter', [ApiEdgeController::class, 'filter'])->middleware('edge.api.key');

// Edge box config (public - LAN only)
Route::get('/edge/config', function () {
    return response()->json([
        'store_id' => config('app.store_id'),
        'api_key' => config('app.api_key'),
        'deployment_mode' => config('app.deployment_mode', 'offline-first'),
        'edge_box_api_key' => config('edge_box.api_key'),
    ]);
});
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
    Route::post('/hold_payment', [PaymentController::class, 'createPayment']);
    Route::match(['GET', 'POST'], '/get_sale_today', [PaymentController::class, 'getSaleToday']);
});
Route::post('/user/payment/list_open', [PaymentController::class, 'listOpen']);
Route::get('/user/payment/get/{id}', [PaymentController::class, 'getPayment']);
Route::get('/user/payment/get-payment-new/{id}', [PaymentController::class, 'getPaymentDetail']);
Route::post('/user/payment/get-payment-new', [PaymentController::class, 'getPaymentDetailByRequest']);
// View payment details for POS web
Route::prefix('edge')->middleware('edge.api.key')->group(function () {
    Route::post('/list-order-new', [PosWebFilterController::class, 'apiEdgeFilterByCondition']);
    
});


Route::prefix('user/payment')->middleware('edge.api.key')->group(function () {
    Route::post('/is_printed', [PaymentController::class, 'checkIsPrinted']);
    Route::post('/get_payment', [PaymentController::class, 'getPaymentByRequest']);
    Route::post('/get_payment_by_table', [PaymentController::class, 'getPaymentByTable']);
    Route::post('/get_all_payment_for_user_new', [PaymentController::class, 'getAllPaymentForUserNew']);
});
Route::match(['GET', 'POST'], '/user/payment/get_all_payment_for_user_new_paginate', [PaymentController::class, 'getAllPaymentForUserNewPaginate']);

Route::prefix('payment')->group(function () {
    Route::get('/print-for-web', [PaymentPrintController::class, 'printForWeb']);
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

// Legacy POS master data endpoints (public read-only)
Route::get('/user/product/list', function (\Illuminate\Http\Request $req) {
    $sid = config('edge_box.store_id');
    $products = \App\Models\Product::with('timePrices', 'category', 'types', 'product_types', 'inventory')
        ->where('store_id', $sid)->where('status', 1)->where('is_show', 1)->orderBy('sort_rank')->get();
    $ctl = app(\App\Http\Controllers\Api\PosWebFilterController::class);
    return $products->map(function ($p) use ($ctl) {
        return $ctl->publicProductPayload($p);
    });
});
// Quick price check endpoint — returns current time-adjusted price for a product
Route::get('/user/product/price/{id}', function (\Illuminate\Http\Request $req, $id) {
    $sid = config('edge_box.store_id');
    $product = \App\Models\Product::with('timePrices')->where('store_id', $sid)->find($id);
    if (!$product) { return response()->json(['status' => false, 'message' => 'Product not found'], 404); }
    $ctl = app(\App\Http\Controllers\Api\PosWebFilterController::class);
    $payload = $ctl->publicProductPayload($product);
    return response()->json([
        'status' => true,
        'price' => $payload['price'],
        'price_after_tax' => $payload['price_after_tax'],
        'unit_price' => $payload['unit_price'] ?? $payload['price'],
    ]);
});
Route::get('/user/category/list', function (\Illuminate\Http\Request $req) {
    $sid = config('edge_box.store_id');
    return \App\Models\Category::where('store_id', $sid)->where('status', 1)->orderBy('sort_order')->get();
});
Route::get('/user/customer/list', function (\Illuminate\Http\Request $req) {
    $sid = config('edge_box.store_id');
    return \App\Models\Customer::where('store_id', $sid)->orderBy('name')->get();
});
Route::get('/user/list', function (\Illuminate\Http\Request $req) {
    $sid = config('edge_box.store_id');
    return \App\Models\User::where('store_id', $sid)->select('id', 'name', 'email', 'phone')->orderBy('name')->get();
});
// Legacy POS table compatibility endpoints
Route::get('/user/table/list', [TableController::class, 'index']);
Route::match(['GET', 'POST'], '/user/table/get_table/{id?}', [TableController::class, 'show']);
Route::prefix('user/table')->middleware('edge.api.key')->group(function () {
    Route::post('/check_in_table', [TableController::class, 'checkIn']);
    Route::post('/check_out_table_new', [TableController::class, 'checkOut']);
    Route::post('/update_order_table', [TableController::class, 'updateOrder']);
    Route::post('/change-table', [TableController::class, 'changeTable']);
    Route::post('/check-payment-printed', [KitchenPrintController::class, 'checkPrintedStatus']);
    Route::match(['GET', 'POST'], '/kitchen/print-all', [KitchenPrintController::class, 'printAll']);
    Route::match(['GET', 'POST'], '/kitchen/print-next-web', [KitchenPrintController::class, 'printNextWeb']);
    Route::match(['GET', 'POST'], '/kitchen/print-on-browser', [KitchenPrintController::class, 'printOnBrowser']);
    Route::match(['GET', 'POST'], '/kitchen/print-real-browser', [KitchenPrintController::class, 'printRealBrowser']);
    Route::post('/kitchen/update-print-all', [KitchenPrintController::class, 'updatePrintedQuantity']);
});

Route::prefix('user/booking')->group(function () {
    Route::get('/list', [\App\Http\Controllers\Api\BookingController::class, 'index']);
    Route::post('/creat-booking', [\App\Http\Controllers\Api\BookingController::class, 'create']);
    Route::post('/update-booking', [\App\Http\Controllers\Api\BookingController::class, 'update']);
});

Route::get('/user/cash-drawer/edit-user', [\App\Http\Controllers\Api\PosWebFilterController::class, 'getCashDrawer']);

Route::prefix('posWeb')->middleware('edge.api.key')->group(function () {
    Route::post('/filter', [PosWebFilterController::class, 'filter']);
});

Route::prefix('admin/product')->middleware('edge.api.key')->group(function () {
    Route::post('/search', [PosWebFilterController::class, 'searchProducts']);
});

Route::post('/user/product/search_products', [PosWebFilterController::class, 'searchProducts'])->middleware('edge.api.key');
Route::post('/user/product/get_product_list', [PosWebFilterController::class, 'getProductList'])->middleware('edge.api.key');
Route::post('/user/category/get_category', [PosWebFilterController::class, 'getCategory'])->middleware('edge.api.key');
Route::post('/user/customer/get_all_customer', [PosWebFilterController::class, 'getAllCustomer'])->middleware('edge.api.key');

Route::prefix('user/payment_detail')->group(function () {
    Route::get('/getServedStatus', [TableController::class, 'getServedStatus']);
    Route::post('/served', [TableController::class, 'served']);
});

Route::post('/update_number_of_people', [TableController::class, 'updateNumberOfPeople'])->middleware('edge.api.key');

// POS supporting endpoints
Route::post('/user/customer/add', [\App\Http\Controllers\Api\PosWebFilterController::class, 'createCustomer']);
Route::get('/user/banking-information', function () {
    $storeId = config('edge_box.store_id') ?? \App\Models\Store::first()?->id;
    $bank = \App\Models\BankPayment::where('store_id', $storeId)->first();
    return response()->json([
        'status' => true,
        'message' => 'success',
        'bank_payment' => $bank ? $bank->toArray() : '',
    ]);
});
Route::post('/user/table/generate-qr', function (\Illuminate\Http\Request $request) {
    $tableId = $request->input('table_id');
    $table = \App\Models\Table::find($tableId);
    if (!$table) {
        return response()->json(['status' => false, 'message' => 'Table not found'], 404);
    }
    $token = \Illuminate\Support\Str::random(32);
    $table->qr_token = $token;
    $table->save();
    return response()->json(['status' => true, 'data' => ['token' => $token]]);
});
Route::get('/user/orders/log', function (\Illuminate\Http\Request $request) {
    $storeId = $request->input('store_id', config('edge_box.store_id'));
    $tableId = $request->input('table_id');
    $query = \App\Models\Payment::with(['table', 'user', 'details'])
        ->where('store_id', $storeId)
        ->whereNotNull('items');
    if ($tableId) {
        $query->where('table_id', $tableId);
    }
    $logs = $query->latest()->take(20)->get();
    $data = $logs->map(function ($payment) {
        $items = json_decode($payment->items, true);
        $itemList = $items['item'] ?? [];
        $contentParts = [];
        foreach ($itemList as $key => $item) {
            $title = $item['title'] ?? $item['product_code'] ?? $key;
            $qty = $item['quantity'] ?? 0;
            $contentParts[] = $title . ' SL:' . $qty;
        }
        $content = implode('&&', $contentParts);
        return [
            'id' => $payment->id,
            'payment_id' => $payment->id,
            'content' => $content,
            'table' => $payment->table ? ['tablename' => $payment->table->tablename ?? $payment->table->name ?? ''] : null,
            'user' => $payment->user ? ['name' => $payment->user->name] : null,
            'user_type' => 'staff',
            'created_at' => $payment->created_at,
        ];
    });
    return response()->json(['status' => true, 'data_log' => $data]);
});

Route::get('/common/payment-status/get-all', [TableController::class, 'getPaymentMethods']);

Route::prefix('user/split-merge-invoice')->middleware('edge.api.key')->group(function () {
    Route::any('/get-list-invoice', [SplitMergeInvoiceController::class, 'getListInvoice']);
    Route::post('/split-invoice', [SplitMergeInvoiceController::class, 'splitInvoice']);
    Route::post('/merge-invoice', [SplitMergeInvoiceController::class, 'mergeInvoice']);
});

Route::prefix('payment')->middleware('edge.api.key')->group(function () {
    Route::get('/print-for-web', [PaymentPrintController::class, 'printForWeb']);
    Route::get('/print', [PaymentPrintController::class, 'printPaymentPdf']);
    Route::post('/print-temporary-split-bill', [PaymentPrintController::class, 'printTemporarySplitPayment']);
});

Route::prefix('admin/payment')->middleware('edge.api.key')->group(function () {
    Route::get('/print_payment', [PaymentPrintController::class, 'printPayment']);
    Route::post('/delete-payment-detail', [PaymentController::class, 'deletePaymentDetail']);
    Route::get('/delete-payment-for-user', [PaymentController::class, 'deletePaymentForUser']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
