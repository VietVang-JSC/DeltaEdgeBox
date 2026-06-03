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

class InventoryOutputController extends Controller
{
    /**
     * Method: createExportInvoice
     * Handle offline checkout transactions by decreasing local inventory quantity,
     * writing history logs, and pushing payload to sync_queue.
     */
    public function createExportInvoice(Request $request)
    {
        $storeId = $request->input('store_id') ?: config('edge_box.store_id', 2);
        $productsInput = $request->input('product_id', []);
        $quantities = $request->input('quantity', []);
        $prices = $request->input('price', []);
        $outputType = $request->input('output_type') ?: 'export';
        $adminId = $request->input('admin_id') ?: 1;

        if (empty($productsInput) || !is_array($productsInput)) {
            return response()->json([
                'status' => false,
                'status_code' => 400,
                'message' => 'Danh sách sản phẩm trống.'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $outputCode = 'PX-EDGE-' . time() . '-' . rand(1000, 9999);
            $date = date('Y-m-d');
            $totalQuantity = 0;
            $totalPrice = 0;

            Log::channel('edge')->info("Offline Checkout Transaction: Starting transaction for Code: {$outputCode}, Store ID: {$storeId}");

            foreach ($productsInput as $key => $productId) {
                $qty = (int) str_replace(',', '', $quantities[$key] ?? 0);
                $price = (double) str_replace(',', '', $prices[$key] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                // 1. Cập nhật số lượng tồn kho cục bộ (trừ bớt)
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
                $inventory->quantity -= $qty;
                $inventory->save();

                // 2. Ghi nhận lịch sử giao dịch cục bộ với giá trị âm để biểu diễn xuất kho
                InventoryHistory::create([
                    'store_id' => $storeId,
                    'product_id' => $productId,
                    'input_id' => 0, // id tạm chờ đồng bộ từ Cloud
                    'input_code' => $outputCode,
                    'input_date' => $date,
                    'quantity' => -$qty, // số âm biểu diễn xuất
                    'admin_id' => $adminId,
                ]);

                $totalQuantity += $qty;
                $totalPrice += ($qty * $price);

                Log::channel('edge')->info("Offline Checkout Transaction Item: Subtracted {$qty} units of Product ID {$productId}. Old stock: {$oldQty}, New stock: {$inventory->quantity}");
            }

            // 3. Ghi nhận vào hàng đợi sync_queues để đồng bộ lên Cloud BE
            $payload = array_merge($request->all(), ['output_code' => $outputCode]);
            SyncQueue::create([
                'store_id' => $storeId,
                'table_name' => 'outputs',
                'operation' => 'create',
                'record_id' => 0, // id tạm
                'payload' => json_encode($payload),
                'status' => 'pending',
                'priority' => 1,
                'retry_count' => 0,
                'max_retries' => 10,
            ]);

            DB::commit();

            Log::channel('edge')->info("Offline Checkout transaction committed successfully. Code: {$outputCode}, Total Qty: {$totalQuantity}, Total Price: {$totalPrice}");

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'Tạo mới phiếu xuất kho thành công (offline)',
                'invoice' => [
                    'id' => $outputCode, // Sử dụng output_code làm ID khi offline để FE có thể xem chi tiết
                    'output_code' => $outputCode,
                    'output_date' => $date,
                    'total_price' => $totalPrice,
                    'total_quantity' => $totalQuantity
                ]
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::channel('edge')->error("Offline Checkout failed: " . $e->getMessage());
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => 'Lỗi xử lý giao dịch xuất kho offline: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Method: exportInvoiceList
     * Returns grouped invoices list aggregated from local inventory_histories table
     */
    public function exportInvoiceList(Request $request)
    {
        $storeId = $request->input('store_id') ?: config('edge_box.store_id', 2);
        $query = $request->input('query', '');
        $pageSize = (int) $request->input('pageSize', 10);
        $currentPage = (int) $request->input('currentPage', 1);

        $historiesQuery = InventoryHistory::where('store_id', $storeId)
            ->where(function ($q) {
                $q->where('quantity', '<', 0)
                  ->orWhere('input_code', 'like', 'PX%');
            })
            ->with('product');

        if (!empty($query)) {
            $historiesQuery->where('input_code', 'like', "%{$query}%");
        }

        $grouped = $historiesQuery->get()
            ->groupBy('input_code')
            ->map(function ($items, $code) {
                $firstItem = $items->first();
                $totalQuantity = abs($items->sum('quantity'));
                $totalPrice = $items->sum(fn($i) => ($i->product ? $i->product->price : 0) * abs($i->quantity));
                return [
                    'id' => $firstItem->input_id ?: $code,
                    'output_code' => $code,
                    'output_date' => $firstItem->input_date,
                    'total_price' => $totalPrice,
                    'total_quantity' => $totalQuantity,
                    'discount' => 0,
                    'user_init' => $firstItem->admin_id,
                    'user_upd' => null,
                    'total_payment_price' => $totalPrice,
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
     * Method: getDetailInvoiceOutput
     * Returns invoice header details and items queried from local inventory_histories
     */
    public function getDetailInvoiceOutput(Request $request)
    {
        $id = $request->input('id');
        $code = $request->input('output_code');

        $query = InventoryHistory::query()->with('product');
        if ($id && is_numeric($id) && $id > 0) {
            $query->where('input_id', $id);
        } else if ($id) {
            $query->where('input_code', $id);
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
        $totalQuantity = abs($items->sum('quantity'));
        $totalPrice = $items->sum(fn($i) => ($i->product ? $i->product->price : 0) * abs($i->quantity));

        $invoiceItems = [];
        foreach ($items as $item) {
            $qty = abs($item->quantity);
            $price = $item->product ? $item->product->price : 0;
            $invoiceItems[] = [
                'id' => $item->id,
                'output_id' => $first->input_id ?: 0,
                'product_id' => $item->product_id,
                'price' => $price,
                'quantity' => $qty,
                'total_price' => $price * $qty,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'code' => $item->product->code,
                    'name' => $item->product->name,
                    'product_code' => $item->product->code,
                    'title' => $item->product->name,
                    'price' => $item->product->price,
                    'image' => $item->product->image,
                    'import_price' => 0,
                ] : null
            ];
        }

        $invoice = [
            'id' => $first->input_id ?: 0,
            'agency_id' => 0,
            'output_code' => $first->input_code,
            'input_id' => null,
            'user_init' => $first->admin_id,
            'user_upd' => null,
            'output_date' => $first->input_date,
            'total_price' => $totalPrice,
            'total_quantity' => $totalQuantity,
            'discount' => 0,
            'total_payment_price' => $totalPrice,
            'payment_method' => 'cash',
            'status' => 'success',
            'output_receiver' => null,
            'output_receiver_phone' => null,
            'output_address' => null,
            'output_type' => 'export',
            'note' => 'Phiếu xuất kho Edge',
            'created_at' => $first->created_at,
            'items' => $invoiceItems,
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
     * Method: updateInvoiceOutput
     * Block update operation in offline mode
     */
    public function updateInvoiceOutput(Request $request)
    {
        return response()->json([
            'status' => false,
            'status_code' => 400,
            'message' => 'Không hỗ trợ sửa phiếu xuất kho ở chế độ offline.'
        ], 400);
    }

    /**
     * Method: deleteInvoiceOutput
     * Block delete operation in offline mode
     */
    public function deleteInvoiceOutput(Request $request)
    {
        return response()->json([
            'status' => false,
            'status_code' => 400,
            'message' => 'Không hỗ trợ hủy phiếu xuất kho ở chế độ offline.'
        ], 400);
    }
}
