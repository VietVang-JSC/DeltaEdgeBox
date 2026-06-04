<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Product;
use App\Models\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryInputController extends Controller
{
    /**
     * Method: addInvoiceInput
     * Handle offline check-in transactions by increasing local inventory quantity,
     * writing history logs, and pushing payload to sync_queue.
     */
    public function addInvoiceInput(Request $request)
    {
        $storeId = $request->input('store_id') ?: config('edge_box.store_id', 2);
        $productsInput = $request->input('product_id', []);
        $quantities = $request->input('quantity', []);
        $prices = $request->input('price', []);
        
        if (empty($productsInput) || !is_array($productsInput)) {
            return response()->json([
                'status' => false,
                'status_code' => 400,
                'message' => 'Danh sách sản phẩm trống.'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $inputCode = 'PN-EDGE-' . time() . '-' . rand(1000, 9999);
            $date = date('Y-m-d');
            $totalQuantity = 0;
            $totalPrice = 0;
            $adminId = $request->input('admin_id') ?: 1;

            Log::info("Offline Check-in Transaction: Starting transaction for Code: {$inputCode}, Store ID: {$storeId}");

            foreach ($productsInput as $key => $productId) {
                $qty = (int) str_replace(',', '', $quantities[$key] ?? 0);
                $price = (double) str_replace(',', '', $prices[$key] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                // 1. Cập nhật số lượng tồn kho cục bộ
                $inventory = Inventory::where('store_id', $storeId)
                                      ->where('product_id', $productId)
                                      ->first();
                if (!$inventory) {
                    $inventory = Inventory::create([
                        'store_id' => $storeId,
                        'product_id' => $productId,
                        'quantity' => 0,
                        'admin_id' => $adminId,
                    ]);
                }
                
                $oldQty = $inventory->quantity;
                $inventory->quantity += $qty;
                $inventory->save();

                // 2. Ghi nhận lịch sử giao dịch cục bộ
                InventoryHistory::create([
                    'store_id' => $storeId,
                    'product_id' => $productId,
                    'input_id' => 0, // id tạm chờ đồng bộ từ Cloud
                    'input_code' => $inputCode,
                    'input_date' => $date,
                    'quantity' => $qty,
                    'admin_id' => $adminId,
                ]);

                $totalQuantity += $qty;
                $totalPrice += ($qty * $price);

                Log::info("Offline Check-in Transaction Item: Added {$qty} units of Product ID {$productId}. Old stock: {$oldQty}, New stock: {$inventory->quantity}");
            }

            // 3. Ghi nhận vào hàng đợi sync_queues để đồng bộ lên Cloud BE
            $payload = array_merge($request->all(), ['input_code' => $inputCode]);
            SyncQueue::create([
                'store_id' => $storeId,
                'table_name' => 'inputs',
                'operation' => 'create',
                'record_id' => 0, // id tạm
                'payload' => json_encode($payload),
                'status' => 'pending',
                'priority' => 1,
                'retry_count' => 0,
                'max_retries' => 10,
            ]);

            DB::commit();

            Log::info("Offline Check-in transaction committed successfully. Code: {$inputCode}, Total Qty: {$totalQuantity}, Total Price: {$totalPrice}");

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'Tạo mới phiếu nhập hàng thành công (offline)',
                'invoice' => [
                    'id' => 0,
                    'input_code' => $inputCode,
                    'input_date' => $date,
                    'total_price' => $totalPrice,
                    'total_quantity' => $totalQuantity
                ]
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Offline Check-in failed: " . $e->getMessage());
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => 'Lỗi xử lý giao dịch nhập kho offline: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Method: getListInvoiceInput
     * Returns grouped invoices list aggregated from local inventory_histories table
     */
    public function getListInvoiceInput(Request $request)
    {
        $storeId = $request->input('store_id') ?: config('edge_box.store_id', 2);
        $query = $request->input('query', '');
        $pageSize = (int) $request->input('pageSize', 10);
        $currentPage = (int) $request->input('currentPage', 1);

        $historiesQuery = InventoryHistory::where('store_id', $storeId)->with('product');
        if (!empty($query)) {
            $historiesQuery->where('input_code', 'like', "%{$query}%");
        }

        $grouped = $historiesQuery->get()
            ->groupBy('input_code')
            ->map(function ($items, $code) {
                $firstItem = $items->first();
                return [
                    'id' => $firstItem->input_id ?: $code,
                    'input_code' => $code,
                    'input_date' => $firstItem->input_date,
                    'total_price' => $items->sum(fn($i) => ($i->product ? $i->product->price : 0) * $i->quantity),
                    'total_quantity' => $items->sum('quantity'),
                    'discount' => 0,
                    'user_init' => $firstItem->admin_id,
                    'user_upd' => null,
                    'total_payment_price' => 0,
                    'payment_method' => 'cash',
                    'status' => 'success',
                    'note' => 'Đồng bộ từ Cloud hoặc Giao dịch Edge',
                    'created_at' => $firstItem->created_at,
                ];
            })
            ->sortByDesc('created_at')
            ->values();

        $count = $grouped->count();
        $total = ceil($count / $pageSize);
        $offset = ($currentPage - 1) * $pageSize;
        $paginated = $grouped->slice($offset, $pageSize)->all();

        return response()->json([
            'status' => true,
            'status_code' => 200,
            'message' => 'success',
            'data' => [
                'total' => $total ?: 1,
                'currentPage' => $currentPage,
                'pageSize' => $pageSize,
                'invoices' => $paginated
            ]
        ]);
    }

    /**
     * Method: getDetailInvoiceInput
     * Returns invoice header details queried from local inventory_histories
     */
    public function getDetailInvoiceInput(Request $request)
    {
        $id = $request->input('id');
        $code = $request->input('input_code');

        $query = InventoryHistory::query()->with('product');
        if ($id && $id > 0) {
            $query->where('input_id', $id);
        } else if ($code) {
            $query->where('input_code', $code);
        } else {
            return response()->json([
                'status' => false,
                'status_code' => 400,
                'message' => 'Yêu cầu thiếu các thông số cần thiết'
            ], 400);
        }

        $items = $query->get();
        if ($items->isEmpty()) {
            return response()->json([
                'status' => false,
                'status_code' => 404,
                'message' => 'Không tìm thấy hóa đơn'
            ], 404);
        }

        $first = $items->first();
        $invoice = [
            'id' => $first->input_id ?: 0,
            'agency_id' => 0,
            'input_code' => $first->input_code,
            'user_init' => $first->admin_id,
            'user_upd' => null,
            'input_date' => $first->input_date,
            'total_price' => $items->sum(fn($i) => ($i->product ? $i->product->price : 0) * $i->quantity),
            'total_quantity' => $items->sum('quantity'),
            'discount' => 0,
            'total_payment_price' => 0,
            'payment_method' => 'cash',
            'status' => 'success',
            'note' => 'Phiếu nhập kho Edge',
            'created_at' => $first->created_at,
        ];

        return response()->json([
            'status' => true,
            'status_code' => 200,
            'message' => 'success',
            'data' => [
                'invoice' => $invoice
            ]
        ]);
    }

    /**
     * Method: updateInvoiceInput
     * Block update operation in offline mode
     */
    public function updateInvoiceInput(Request $request)
    {
        return response()->json([
            'status' => false,
            'status_code' => 400,
            'message' => 'Không hỗ trợ sửa phiếu nhập hàng ở chế độ offline.'
        ], 400);
    }

    /**
     * Method: deleteInvoiceInput
     * Block delete operation in offline mode
     */
    public function deleteInvoiceInput(Request $request)
    {
        return response()->json([
            'status' => false,
            'status_code' => 400,
            'message' => 'Không hỗ trợ hủy phiếu nhập hàng ở chế độ offline.'
        ], 400);
    }
}
