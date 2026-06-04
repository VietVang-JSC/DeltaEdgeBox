<?php

namespace App\Http\Controllers\Api;

use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\CheckInventory;
use App\Models\CheckInventoryItems;
use App\Models\Product;
use App\Services\SyncService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class InventoryApiController extends Controller
{
    /**
     * Protected response helper that mimics Cloud BE getMessage function signature.
     */
    protected function getMessage($status, $message, $statusCode, $key = null, $data = null)
    {
        $response = [
            'status' => $status,
            'status_code' => $statusCode,
            'message' => $message,
        ];

        if ($key !== null) {
            $response[$key] = $data;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * 1. GET /api/admin/inventory/get_inventory_list
     * Mimics Cloud BE getIventoryList
     */
    public function getInventoryList(Request $request)
    {
        $params = $request->all();
        Log::info('Edge Inventory: Calling getInventoryList', ['params' => $params]);
        try {
            $store_id = (int) $request->input('store_id', $request->query('store_id', config('app.store_id')));
            $currentPage = 1;
            $pageSize = 10;
            $queryStr = '';

            if (isset($params['pageSize'])) {
                $pageSize = (int) $params['pageSize'];
                if ($pageSize > 25) {
                    $pageSize = 25;
                } elseif ($pageSize <= 0) {
                    $pageSize = 10;
                }
            }

            if (isset($params['currentPage'])) {
                $currentPage = (int) $params['currentPage'];
                if ($currentPage <= 0) {
                    $currentPage = 1;
                }
            }
            
            if (isset($params['query'])) {
                $queryStr = $params['query'];
            }

            $qb = Inventory::with(['product'])
                ->where('store_id', $store_id);

            if ($queryStr !== '') {
                $qb->whereHas('product', function ($q) use ($queryStr) {
                    $q->where('code', 'like', "%{$queryStr}%")
                      ->orWhere('name', 'like', "%{$queryStr}%");
                });
            }

            $count = $qb->count();
            $total = ceil($count / $pageSize);

            $inventory_list = [];
            if ($count > 0) {
                $inventory_list = $qb->take($pageSize)
                    ->skip(($currentPage - 1) * $pageSize)
                    ->get()
                    ->toArray();

                // Format image paths to match root and storage paths perfectly
                foreach ($inventory_list as $key => $item) {
                    if (isset($item['product']['image'])) {
                        $name_image_product_default = 'default_product';
                        if (strpos($item['product']['image'], $name_image_product_default) !== false) {
                            $inventory_list[$key]['product']['image'] = $request->root() . "/" . $item['product']['image'];
                        } else {
                            $inventory_list[$key]['product']['image'] = $request->root() . "/storage/" . $item['product']['image'];
                        }
                    }
                }
            }

            $data = [
                'total' => $total,
                'currentPage' => $currentPage,
                'pageSize' => $pageSize,
                'inventory_list' => $inventory_list
            ];

            Log::info('Edge Inventory: getInventoryList success', ['total_records' => $count]);
            return $this->getMessage(true, 'success', 200, 'data', $data);
        } catch (\Throwable $th) {
            Log::error('Edge Inventory: getInventoryList failed', ['error' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);
            return $this->getMessage(false, 'Đã có lỗi xảy ra', 500);
        }
    }

    /**
     * 2. GET /api/admin/inventory/get_inventory_detail_by_product_id
     * Mimics Cloud BE getInventoryDetailByProductID
     */
    public function getInventoryDetailByProductId(Request $request)
    {
        $params = $request->all();
        Log::info('Edge Inventory: Calling getInventoryDetailByProductId', ['params' => $params]);
        
        $validator = Validator::make($request->all(), [
            'product_id' => ['required'],
        ]);

        if ($validator->fails()) {
            Log::error('Edge Inventory: getInventoryDetailByProductId validation failed', ['errors' => $validator->errors()]);
            return $this->getMessage(false, $validator->errors(), 400);
        }

        try {
            $store_id = (int) $request->input('store_id', $request->query('store_id', config('app.store_id')));
            $product_id = (int) $request->input('product_id');

            $getData = InventoryHistory::with(['product'])
                ->where('store_id', $store_id)
                ->where('product_id', $product_id)
                ->get();

            if ($getData->isEmpty()) {
                Log::info('Edge Inventory: getInventoryDetailByProductId empty result', ['store_id' => $store_id, 'product_id' => $product_id]);
                return $this->getMessage(false, 'Danh sách chi tiết tồn kho rỗng', 404);
            }

            Log::info('Edge Inventory: getInventoryDetailByProductId success', ['records_count' => $getData->count()]);
            return $this->getMessage(true, 'Lấy chi tiết tồn kho thành công', 200, 'inventory_detail', $getData->toArray());
        } catch (\Throwable $th) {
            Log::error('Edge Inventory: getInventoryDetailByProductId failed', ['error' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);
            return $this->getMessage(false, 'Đã có lỗi xảy ra', 500);
        }
    }

    /**
     * 3. GET /api/admin/inventory/getInventoryCheckList
     * Mimics Cloud BE getInventoryCheckList
     */
    public function getInventoryCheckList(Request $request)
    {
        $params = $request->all();
        Log::info('Edge Inventory: Calling getInventoryCheckList', ['params' => $params]);
        try {
            $store_id = (int) $request->input('store_id', $request->query('store_id', config('app.store_id')));
            $currentPage = 1;
            $pageSize = 10;
            $query = '';

            if (isset($params['pageSize'])) {
                $pageSize = (int) $params['pageSize'];
                if ($pageSize > 25) {
                    $pageSize = 25;
                } elseif ($pageSize <= 0) {
                    $pageSize = 10;
                }
            }

            if (isset($params['currentPage'])) {
                $currentPage = (int) $params['currentPage'];
                if ($currentPage <= 0) {
                    $currentPage = 1;
                }
            }
            
            if (isset($params['query'])) {
                $query = $params['query'];
            }

            $qb = CheckInventory::where('store_id', $store_id);

            if ($query !== '') {
                $qb->where(function ($q) use ($query) {
                    $q->where('inventory_check_code', 'like', "%{$query}%")
                      ->orWhere('inventory_check_date', 'like', "%{$query}%");
                });
            }

            $count = $qb->count();
            $total = ceil($count / $pageSize);

            $invoices = [];
            if ($count > 0) {
                $invoices = $qb->orderBy('created_at', 'desc')
                    ->take($pageSize)
                    ->skip(($currentPage - 1) * $pageSize)
                    ->get()
                    ->toArray();
            }

            $data = [
                'total' => $total,
                'currentPage' => $currentPage,
                'pageSize' => $pageSize,
                'invoices' => $invoices
            ];

            Log::info('Edge Inventory: getInventoryCheckList success', ['total_sheets' => $count]);
            return $this->getMessage(true, 'success', 200, 'data', $data);
        } catch (\Throwable $th) {
            Log::error('Edge Inventory: getInventoryCheckList failed', ['error' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);
            return $this->getMessage(false, 'Đã có lỗi xảy ra', 500);
        }
    }

    /**
     * 4. GET /api/admin/inventory/getInventoryCheckDetail
     * Mimics Cloud BE getInventoryCheckDetail
     */
    public function getInventoryCheckDetail(Request $request)
    {
        $params = $request->all();
        Log::info('Edge Inventory: Calling getInventoryCheckDetail', ['params' => $params]);
        
        $id = $request->input('id');
        $inventory_check_code = $request->input('inventory_check_code');

        if (!$id && !$inventory_check_code) {
            Log::error('Edge Inventory: getInventoryCheckDetail validation failed, missing parameters');
            return $this->getMessage(false, 'Yêu cầu của bạn thiếu các thông số cần thiết.', 400);
        }

        try {
            $store_id = (int) $request->input('store_id', $request->query('store_id', config('app.store_id')));
            
            $qb = CheckInventory::with(['check_inventory_items', 'user_init', 'user_upd'])
                ->where('store_id', $store_id);

            if ($id) {
                $qb->where('id', $id);
            } else {
                $qb->where('inventory_check_code', $inventory_check_code);
            }

            $invoice = $qb->first();

            if ($invoice) {
                Log::info('Edge Inventory: getInventoryCheckDetail success', ['id' => $invoice->id]);
                return $this->getMessage(true, 'success', 200, 'data', ['invoice' => $invoice->toArray()]);
            } else {
                $searchVal = $id ?: $inventory_check_code;
                Log::error('Edge Inventory: getInventoryCheckDetail not found', ['search' => $searchVal]);
                return $this->getMessage(false, "Không tìm thấy hóa đơn: {$searchVal}", 404);
            }
        } catch (\Throwable $th) {
            Log::error('Edge Inventory: getInventoryCheckDetail failed', ['error' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);
            return $this->getMessage(false, 'Đã có lỗi xảy ra', 500);
        }
    }

    /**
     * 5. POST /api/admin/inventory/checkInventoryStore
     * Mimics Cloud BE checkInventoryStore
     */
    public function checkInventoryStore(Request $request)
    {
        $params = $request->all();
        Log::info('Edge Inventory: Calling checkInventoryStore', ['params' => $params]);
        
        $user_id = (int) $request->input('user_id', 1);
        $admin_id = (int) $request->input('admin_id', 1);
        $store_id = (int) $request->input('store_id', config('app.store_id'));

        $params['user_init'] = $user_id;

        try {
            if (isset($params['product_id']) && is_array($params['product_id'])) {
                $items = [];
                $total_discrepancies = 0;
                $date = date('Y-m-d');

                $handle = $this->handleItem($params, $items, $admin_id, $store_id, $total_discrepancies);
                if ($handle['status'] == true) {
                    $params = $handle['params'];
                    $items = $handle['items'];
                } else {
                    Log::error('Edge Inventory: checkInventoryStore item handling failed', ['errors' => $handle['errors']]);
                    return $this->getMessage(false, 'Đã có lỗi xảy ra', 404, 'errors', $handle['errors']);
                }

                if (!isset($params['inventory_check_code']) || empty($params['inventory_check_code'])) {
                    $code = $this->generateCodeCheckInventory($store_id);
                    if ($code == false) {
                        Log::error('Edge Inventory: checkInventoryStore code generation failed');
                        return $this->getMessage(false, 'Không thể tạo mã phiếu kiểm kho', 400, 'errors', ['code' => 'fail']);
                    }
                    $params['inventory_check_code'] = $code;
                } else {
                    $checkCodeExits = CheckInventory::where('inventory_check_code', $params['inventory_check_code'])->first();
                    if (!empty($checkCodeExits)) {
                        Log::error('Edge Inventory: checkInventoryStore code already exists', ['code' => $params['inventory_check_code']]);
                        return $this->getMessage(false, 'Mã phiếu kiểm kho đã tồn tại', 400, 'errors', ['code' => 'exists']);
                    }
                }

                $params['admin_id'] = $admin_id;
                $params['store_id'] = $store_id;
                $params['inventory_check_date'] = $date;

                $createRecord = DB::transaction(function () use ($params, $items) {
                    $checkInventory = CheckInventory::create($params);
                    foreach ($items as $item) {
                        $item['check_inventory_id'] = $checkInventory->id;
                        CheckInventoryItems::create($item);
                    }
                    return $checkInventory;
                });

                if ($createRecord) {
                    $createRecord->load(['check_inventory_items']);
                    Log::info('Edge Inventory: checkInventoryStore success', ['id' => $createRecord->id, 'code' => $createRecord->inventory_check_code]);

                    // Queue sync to cloud
                    try {
                        app(SyncService::class)->queueForSync(
                            'check_inventory',
                            'create',
                            $createRecord->id,
                            $createRecord->toArray()
                        );
                        Log::info('Edge Inventory: Queued checkInventoryStore creation for sync', ['id' => $createRecord->id]);
                    } catch (\Throwable $qe) {
                        Log::warning('Edge Inventory: Failed to queue sync for checkInventoryStore', ['error' => $qe->getMessage()]);
                    }

                    return $this->getMessage(true, 'Tạo mới phiếu kiểm kho thành công', 200, 'invoice', $createRecord->toArray());
                }

                Log::error('Edge Inventory: checkInventoryStore failed to insert record');
                return $this->getMessage(false, 'Không thể khởi tạo phiếu kiểm kho', 400, 'errors', ['check_inventory' => 'Không thể khởi tạo phiếu kiểm kho']);
            }
            Log::error('Edge Inventory: checkInventoryStore invalid request payload (product_id missing or not array)');
            return $this->getMessage(false, 'Yêu cầu không hợp lệ', 400);
        } catch (\Throwable $th) {
            Log::error('Edge Inventory: checkInventoryStore failed', ['error' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);
            return $this->getMessage(false, 'Đã có lỗi xảy ra', 500);
        }
    }

    /**
     * 6. POST /api/admin/inventory/updateInventoryCheck
     * Mimics Cloud BE updateInventoryCheck
     */
    public function updateInventoryCheck(Request $request)
    {
        $params = $request->all();
        Log::info('Edge Inventory: Calling updateInventoryCheck', ['params' => $params]);
        
        $user_id = (int) $request->input('user_id', 1);
        $admin_id = (int) $request->input('admin_id', 1);
        $store_id = (int) $request->input('store_id', config('app.store_id'));

        try {
            if (isset($params['check_inventory_id'])) {
                $checkInventory = CheckInventory::where('id', $params['check_inventory_id'])
                    ->where('store_id', $store_id)
                    ->first();
                if ($checkInventory) {
                    $items = [];
                    $total_quantity = 0;

                    if ($checkInventory->check_inventory_status === 'success') {
                        Log::info('Edge Inventory: updateInventoryCheck sheet is already success, unsetting item modifications');
                        unset($params['inventory_number']);
                        unset($params['actual_quantity']);
                        unset($params['product_code']);
                        unset($params['product_title']);
                        unset($params['product_id']);
                        unset($params['discrepancy_quantity']);
                    } else {
                        if (isset($params['product_id']) && is_array($params['product_id'])) {
                            $handle = $this->handleItem($params, $items, $admin_id, $store_id, $total_quantity);
                            if ($handle['status'] == true) {
                                $params = $handle['params'];
                                $items = $handle['items'];
                            } else {
                                Log::error('Edge Inventory: updateInventoryCheck handleItem failed', ['errors' => $handle['errors']]);
                                return $this->getMessage(false, 'Đã có lỗi xảy ra', 404, 'errors', $handle['errors']);
                            }
                        } else {
                            Log::error('Edge Inventory: updateInventoryCheck product details not found in request');
                            return $this->getMessage(false, 'Không tìm thấy thông tin sản phẩm', 404);
                        }
                    }

                    $params['user_upd'] = $user_id;

                    $updatedInvoice = DB::transaction(function () use ($checkInventory, $params, $items) {
                        $checkInventory->update($params);

                        if ($checkInventory->check_inventory_status !== 'success') {
                            $existingItemProductIds = CheckInventoryItems::where('check_inventory_id', $checkInventory->id)
                                ->pluck('product_id')
                                ->toArray();

                            $newItemProductIds = [];
                            foreach ($items as $item) {
                                $newItemProductIds[] = (int) $item['product_id'];
                                CheckInventoryItems::updateOrCreate(
                                    [
                                        'check_inventory_id' => $checkInventory->id,
                                        'product_id' => $item['product_id'],
                                    ],
                                    $item
                                );
                            }

                            $toDeleteProductIds = array_diff($existingItemProductIds, $newItemProductIds);
                            if (!empty($toDeleteProductIds)) {
                                CheckInventoryItems::where('check_inventory_id', $checkInventory->id)
                                    ->whereIn('product_id', $toDeleteProductIds)
                                    ->delete();
                            }
                        }
                        
                        return $checkInventory;
                    });

                    if ($updatedInvoice) {
                        $updatedInvoice->load(['check_inventory_items']);
                        Log::info('Edge Inventory: updateInventoryCheck success', ['id' => $updatedInvoice->id]);

                        // Queue sync to cloud
                        try {
                            app(SyncService::class)->queueForSync(
                                'check_inventory',
                                'update',
                                $updatedInvoice->id,
                                $updatedInvoice->toArray()
                            );
                            Log::info('Edge Inventory: Queued updateInventoryCheck for sync', ['id' => $updatedInvoice->id]);
                        } catch (\Throwable $qe) {
                            Log::warning('Edge Inventory: Failed to queue sync for updateInventoryCheck', ['error' => $qe->getMessage()]);
                        }

                        return $this->getMessage(true, 'Cập nhật phiếu kiểm kho thành công', 200, 'invoice', $updatedInvoice->toArray());
                    }

                    Log::error('Edge Inventory: updateInventoryCheck fail, failed transaction');
                    return $this->getMessage(false, 'Cập nhật phiếu kiểm kho không thành công', 400, 'errors', ['check_inventory' => 'Cập nhật phiếu kiểm kho không thành công']);
                }
            }
            Log::error('Edge Inventory: updateInventoryCheck not found check inventory sheet', ['check_inventory_id' => $params['check_inventory_id'] ?? null]);
            return $this->getMessage(false, 'Không tìm thấy hóa đơn', 409);
        } catch (\Throwable $th) {
            Log::error('Edge Inventory: updateInventoryCheck failed', ['error' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);
            return $this->getMessage(false, 'Đã có lỗi xảy ra', 500);
        }
    }

    /**
     * 7. DELETE /api/admin/inventory/deleteInventoryCheck
     * Mimics Cloud BE deleteInventoryCheck
     */
    public function deleteInventoryCheck(Request $request)
    {
        $params = $request->all();
        Log::info('Edge Inventory: Calling deleteInventoryCheck', ['params' => $params]);
        
        $validator = Validator::make($request->all(), [
            'check_inventory_id' => ['required'],
        ]);

        if ($validator->fails()) {
            Log::error('Edge Inventory: deleteInventoryCheck validation failed', ['errors' => $validator->errors()]);
            return $this->getMessage(false, $validator->errors(), 400);
        }

        $check_inventory_id = $request->input('check_inventory_id');
        $store_id = (int) $request->input('store_id', $request->query('store_id', config('app.store_id')));

        try {
            $checkInventory = CheckInventory::where('id', $check_inventory_id)
                ->where('store_id', $store_id)
                ->first();
            if ($checkInventory) {
                // Keep a copy of data before deletion
                $checkInventoryData = $checkInventory->toArray();

                DB::transaction(function () use ($checkInventory) {
                    CheckInventoryItems::where('check_inventory_id', $checkInventory->id)->delete();
                    $checkInventory->delete();
                });

                Log::info('Edge Inventory: deleteInventoryCheck success', ['id' => $check_inventory_id]);

                // Queue sync to cloud
                try {
                    app(SyncService::class)->queueForSync(
                        'check_inventory',
                        'delete',
                        (int) $check_inventory_id,
                        $checkInventoryData
                    );
                    Log::info('Edge Inventory: Queued deleteInventoryCheck for sync', ['id' => $check_inventory_id]);
                } catch (\Throwable $qe) {
                    Log::warning('Edge Inventory: Failed to queue sync for deleteInventoryCheck', ['error' => $qe->getMessage()]);
                }

                return $this->getMessage(true, 'Xóa phiếu kiểm kho thành công', 200);
            }
            Log::error('Edge Inventory: deleteInventoryCheck failed, record not found', ['id' => $check_inventory_id, 'store_id' => $store_id]);
            return $this->getMessage(false, 'Xóa phiếu kiểm kho thất bại', 404);
        } catch (\Throwable $th) {
            Log::error('Edge Inventory: deleteInventoryCheck failed', ['error' => $th->getMessage(), 'trace' => $th->getTraceAsString()]);
            return $this->getMessage(false, 'Đã có lỗi xảy ra', 500);
        }
    }

    /**
     * Helper to auto generate unique check inventory code.
     */
    private function generateCodeCheckInventory($store_id, $attempt = 0)
    {
        if ($attempt > 10) {
            return false;
        }
        $date = now();
        $random = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code = 'KK' . $store_id . $date->format('y') . $date->format('md') . '-' . $random;
        
        $checkExists = CheckInventory::where('inventory_check_code', $code)->first();
        if ($checkExists) {
            return $this->generateCodeCheckInventory($store_id, $attempt + 1);
        }
        return $code;
    }

    /**
     * Helper to process item parameters and calculate discrepancy quantities.
     */
    private function handleItem($params, $items, $admin_id, $store_id, &$total_discrepancies)
    {
        $increased_discrepancy_quantity = 0;
        $decreased_discrepancy_quantity = 0;

        foreach ($params['product_id'] as $key => $id) {
            if (isset($params['discrepancy_quantity'][$key]) && isset($params['actual_quantity'][$key]) && isset($params['inventory_number'][$key])) {
                $product = Product::where('id', $id)
                    ->where('store_id', $store_id)
                    ->first();
                if ($product) {
                    $inventory_number = (int) str_replace(',', '', $params['inventory_number'][$key]);
                    $actual_quantity = (int) str_replace(',', '', $params['actual_quantity'][$key]);
                    $discrepancy_quantity = (int) str_replace(',', '', $params['discrepancy_quantity'][$key]);

                    $increased_discrepancy_quantity += $discrepancy_quantity >= 0 ? $discrepancy_quantity : 0;
                    $decreased_discrepancy_quantity += $discrepancy_quantity < 0 ? $discrepancy_quantity : 0;

                    $items[] = [
                        'product_id' => $id,
                        'inventory_number' => $inventory_number,
                        'actual_quantity' => $actual_quantity,
                        'discrepancy_quantity' => $discrepancy_quantity,
                        'admin_id' => $admin_id,
                        'store_id' => $store_id,
                    ];
                    $total_discrepancies += $discrepancy_quantity;
                } else {
                    return [
                        'status' => false,
                        'errors' => [
                            'product' => 'Không tìm thấy sản phẩm có id là ' . $id
                        ]
                    ];
                }
            } else {
                return [
                    'status' => false,
                    'errors' => [
                        'price' => 'Không tìm thấy giá trị chênh lệch hoặc giá trị thực tế hoặc giá trị tồn kho của sản phẩm có ID là ' . $id
                    ]
                ];
            }
        }

        $params['increased_discrepancy_quantity'] = $increased_discrepancy_quantity;
        $params['decreased_discrepancy_quantity'] = $decreased_discrepancy_quantity;
        $params['total_discrepancies'] = $total_discrepancies;
        $params['check_inventory_status'] = $params['check_inventory_status'] ?? 'temporary';

        unset($params['inventory_number']);
        unset($params['actual_quantity']);
        unset($params['product_code']);
        unset($params['product_title']);
        unset($params['product_id']);
        unset($params['discrepancy_quantity']);

        return [
            'status' => true,
            'params' => $params,
            'items' => $items,
        ];
    }
}
