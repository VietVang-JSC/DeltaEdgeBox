<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Store;
use App\Models\Table;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    private const STATUS_TABLE_ACTIVE = 1;
    private const STATUS_PAYMENT_ACTIVE = 1;

    public function createPayment(Request $request)
    {
        app()->setLocale($request->input('isCheckLanguage', 'vi'));

        $validator = Validator::make($request->all(), [
            'items' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'status_code' => 400,
                'message' => $validator->errors(),
            ], 400);
        }

        try {
            $items = $this->decodeItems($request->input('items'));
            $summary = $this->summarizeItems($items);

            $payment = DB::transaction(function () use ($request, $items, $summary) {
                $status = (int) $request->input('status', self::STATUS_PAYMENT_ACTIVE);
                $tableId = $request->input('table_id', $request->input('tableID'));
                $storeId = (int) $request->input('store_id', config('app.store_id'));
                $userId = (int) $request->input('user_id', 1);
                $paymentTime = $this->storeNow($storeId);

                $payment = Payment::create([
                    'payment_code' => $request->input('payment_code') ?: 'EDGE-' . $paymentTime->format('YmdHis') . '-' . random_int(1000, 9999),
                    'store_id' => $storeId,
                    'table_id' => $tableId,
                    'customer_id' => $request->input('customer_id'),
                    'items' => $this->normalizeItemsPayload($request->input('items')),
                    'paid_date' => $paymentTime,
                    'total' => round((float) $request->input('valuetotal', $summary['total'])),
                    'discount' => (float) $request->input('discount', 0),
                    'tax' => (float) $request->input('total_tax', 0),
                    'final_total' => round((float) $request->input('amount_received', $request->input('valuetotal', $summary['total']))),
                    'payment_method' => $request->input('payment_method', 'cash') ?: 'cash',
                    'note' => $request->input('reason'),
                    'reason' => $request->input('reason'),
                    'status' => $status,
                    'user_id' => $userId,
                    'admin_id' => $request->input('admin_id', $userId),
                    'surcharge' => (float) $request->input('surcharge', 0),
                    'surcharge_reason' => $request->input('surcharge_reason'),
                    'surcharge_percent' => (int) $request->input('surcharge_percent', 0),
                    'service_charge' => (int) $request->input('service_charge', 0),
                    'service_charge_amount' => (float) $request->input('service_charge_amount', 0),
                    'type_discount' => $request->input('type_discount', 'amount'),
                    'discount_percent' => (int) $request->input('discount_percent', 0),
                    'is_senior_discount' => $request->input('is_senior_discount', false),
                    'senior_discount_amount' => (float) $request->input('senior_discount_amount', 0),
                    'sub_total_before_discount' => (float) $request->input('sub_total_before_discount', 0),
                    'total_incl_vat_before_discount' => (float) $request->input('total_incl_vat_before_discount', 0),
                    'created_at' => $paymentTime,
                    'updated_at' => $paymentTime,
                ]);

                foreach ($items as $item) {
                    PaymentDetail::create([
                        'payment_id' => $payment->id,
                        'product_id' => $item['product_id'],
                        'product_key' => $item['product_key'] ?? null,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'total' => $item['total'],
                        'note' => $item['note'] ?? null,
                        'admin_id' => $request->input('admin_id', $userId),
                        'store_id' => $storeId,
                        'created_at' => $paymentTime,
                        'updated_at' => $paymentTime,
                    ]);
                }

                if ($status === self::STATUS_PAYMENT_ACTIVE) {
                    $this->deductInventoryForPayment($payment, $items, $storeId, $userId);
                    $this->clearTableAfterPayment($tableId, $storeId);
                }

                return $payment->load('details');
            });

            try {
                app(SyncService::class)->processQueue(10);
            } catch (\Throwable $th) {
                Log::warning('Edge payment sync failed', ['error' => $th->getMessage()]);
            }

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.payment_create'),
                'data' => [
                    'payment' => $payment->toArray(),
                ],
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge payment create failed', [
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.ISError'),
            ], 500);
        }
    }

    public function updatePayment(Request $request)
    {
        app()->setLocale($request->input('isCheckLanguage', 'vi'));

        $validator = Validator::make($request->all(), [
            'id' => ['required'],
            'items' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'status_code' => 400,
                'message' => $validator->errors(),
            ], 400);
        }

        try {
            $items = $this->decodeItems($request->input('items'));
            $summary = $this->summarizeItems($items);

            $payment = DB::transaction(function () use ($request, $items, $summary) {
                $storeId = (int) $request->input('store_id', config('app.store_id'));
                $payment = Payment::whereKey($request->input('id'))
                    ->where('store_id', $storeId)
                    ->lockForUpdate()
                    ->first();

                if (!$payment) {
                    throw new \RuntimeException('Payment not found');
                }

                $oldStatus = (int) $payment->status;
                $status = (int) $request->input('status', $oldStatus);
                $userId = (int) $request->input('user_id', $payment->user_id ?: 1);
                $paymentTime = $this->storeNow($storeId);

                $payment->fill([
                    'table_id' => $request->input('table_id', $payment->table_id),
                    'customer_id' => $request->input('customer_id', $payment->customer_id),
                    'items' => $this->normalizeItemsPayload($request->input('items')),
                    'paid_date' => $status === self::STATUS_PAYMENT_ACTIVE ? $paymentTime : $payment->paid_date,
                    'total' => round((float) $request->input('valuetotal', $summary['total'])),
                    'discount' => (float) $request->input('discount', 0),
                    'surcharge' => (float) $request->input('surcharge', 0),
                    'surcharge_reason' => $request->input('surcharge_reason'),
                    'surcharge_percent' => (int) $request->input('surcharge_percent', 0),
                    'service_charge' => (int) $request->input('service_charge', 0),
                    'service_charge_amount' => (float) $request->input('service_charge_amount', 0),
                    'tax' => (float) $request->input('total_tax', 0),
                    'final_total' => round((float) $request->input('amount_received', $request->input('valuetotal', $summary['total']))),
                    'payment_method' => $request->input('payment_method', $payment->payment_method ?: 'cash'),
                    'note' => $request->input('reason'),
                    'reason' => $request->input('reason'),
                    'status' => $status,
                    'user_id' => $userId,
                    'admin_id' => $request->input('admin_id', $payment->admin_id ?: $userId),
                    'type_discount' => $request->input('type_discount', 'amount'),
                    'discount_percent' => (int) $request->input('discount_percent', 0),
                    'is_senior_discount' => $request->input('is_senior_discount', false),
                    'senior_discount_amount' => (float) $request->input('senior_discount_amount', 0),
                    'sub_total_before_discount' => (float) $request->input('sub_total_before_discount', 0),
                    'total_incl_vat_before_discount' => (float) $request->input('total_incl_vat_before_discount', 0),
                    'updated_at' => $paymentTime,
                ]);
                $payment->save();

                $payment->details()->delete();
                foreach ($items as $item) {
                    PaymentDetail::create([
                        'payment_id' => $payment->id,
                        'product_id' => $item['product_id'],
                        'product_key' => $item['product_key'] ?? null,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'total' => $item['total'],
                        'note' => $item['note'] ?? null,
                        'admin_id' => $request->input('admin_id', $payment->admin_id ?: $userId),
                        'store_id' => $storeId,
                        'created_at' => $paymentTime,
                        'updated_at' => $paymentTime,
                    ]);
                }

                if ($oldStatus !== self::STATUS_PAYMENT_ACTIVE && $status === self::STATUS_PAYMENT_ACTIVE) {
                    $this->deductInventoryForPayment($payment, $items, $storeId, $userId);
                    $this->clearTableAfterPayment($payment->table_id, $storeId);
                }

                return $payment->load('details');
            });

            try {
                app(SyncService::class)->processQueue(10);
            } catch (\Throwable $th) {
                Log::warning('Edge payment update sync failed', ['error' => $th->getMessage()]);
            }

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.payment_update'),
                'data' => [
                    'payment' => $payment->toArray(),
                ],
            ]);
        } catch (\RuntimeException $th) {
            return response()->json([
                'status' => false,
                'status_code' => 404,
                'message' => $th->getMessage(),
            ], 404);
        } catch (\Throwable $th) {
            Log::error('Edge payment update failed', [
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.ISError'),
            ], 500);
        }
    }

    private function storeNow(int $storeId)
    {
        $timeZone = Store::whereKey($storeId)->value('time_zone') ?: config('app.timezone', 'Asia/Ho_Chi_Minh');

        return now($timeZone);
    }

    private function normalizeItemsPayload($items): string
    {
        if (is_string($items)) {
            return $items;
        }

        return json_encode(array_key_exists('item', $items) ? $items : ['item' => $items]);
    }

    private function decodeItems($items): array
    {
        $decoded = is_string($items) ? json_decode($items, true) : $items;
        $rawItems = $decoded['item'] ?? $decoded ?? [];

        return array_values(array_filter(array_map(function ($item) {
            if (!is_array($item)) {
                return null;
            }

            $productId = $item['product_id'] ?? $item['id'] ?? null;
            if (empty($productId)) {
                return null;
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);

            return [
                'product_id' => (int) $productId,
                'product_key' => $item['product_key'] ?? $item['key'] ?? null,
                'quantity' => $quantity,
                'price' => $price,
                'total' => (float) ($item['TotalPrice'] ?? $item['total'] ?? ($price * $quantity)),
                'note' => $item['note'] ?? null,
            ];
        }, $rawItems)));
    }

    private function deductInventoryForPayment(Payment $payment, array $items, int $storeId, int $userId): void
    {
        foreach ($items as $item) {
            // Check if product requires inventory tracking
            $product = Product::find($item['product_id']);
            if ($product && (int)($product->inventory_required ?? 0) === 0) {
                continue;
            }

            $inventory = Inventory::where('store_id', $storeId)
                ->where('product_id', $item['product_id'])
                ->lockForUpdate()
                ->first();

            if (!$inventory) {
                continue;
            }

            $quantity = (int) $item['quantity'];
            if ($quantity <= 0) {
                continue;
            }

            if ($inventory->quantity < $quantity) {
                throw new \RuntimeException("Inventory insufficient for product {$item['product_id']}: {$inventory->quantity}, needed: {$quantity}");
            }

            $inventory->quantity -= $quantity;
            $inventory->reserved_quantity = max(0, (int) $inventory->reserved_quantity - $quantity);
            $inventory->save();
        }
    }

    private function clearTableAfterPayment($tableId, int $storeId): void
    {
        if (empty($tableId)) {
            return;
        }

        Table::whereKey($tableId)
            ->where('store_id', $storeId)
            ->update([
                'status' => self::STATUS_TABLE_ACTIVE,
                'user_id' => null,
                'listitem' => null,
                'userordered' => null,
                'qr_token' => null,
                'lock_time' => null,
                'payment_id' => null,
                'number_of_people' => 0,
                'can_order' => 1,
                'is_order_enabled' => 1,
                'pin' => null,
            ]);
    }

    private function summarizeItems(array $items): array
    {
        return [
            'total' => array_sum(array_map(function ($item) {
                return (float) ($item['total'] ?? 0);
            }, $items)),
        ];
    }

    public function deletePaymentDetail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => ['required'],
            'product_key' => ['required'],
            'delete_quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'status_code' => 400,
                'message' => $validator->errors()->first(),
            ], 400);
        }

        try {
            $paymentId = $request->input('payment_id');
            $productKey = $request->input('product_key');
            $deleteQuantity = (int) $request->input('delete_quantity');
            $deletePayment = filter_var($request->input('delete_payment'), FILTER_VALIDATE_BOOLEAN);
            $tableId = $request->input('table_id');
            $storeId = (int) $request->input('store_id', config('app.store_id', 1));

            $payment = Payment::whereKey($paymentId)->where('store_id', $storeId)->first();
            if (!$payment) {
                return response()->json([
                    'status' => false,
                    'status_code' => 404,
                    'message' => 'Không tìm thấy thông tin hóa đơn',
                ], 404);
            }

            $productList = $request->input('product_list');
            $discount = (float) $request->input('discount', 0);
            $surcharge = (float) $request->input('surcharge', 0);
            $totalTax = (float) $request->input('total_tax', 0);
            $valuetotal = (float) $request->input('valuetotal', 0);

            $payment = DB::transaction(function () use ($payment, $productKey, $deleteQuantity, $deletePayment, $tableId, $storeId, $productList, $discount, $surcharge, $totalTax, $valuetotal) {
                if ($deletePayment) {
                    if (!empty($tableId)) {
                        $this->clearTableAfterPayment($tableId, $storeId);
                    }
                    $payment->status = -1;
                    $payment->save();
                    $payment->details()->delete();
                    $payment->delete();
                } else {
                    if (!empty($productList) && is_array($productList)) {
                        // Use product_list from FE (same as cloud)
                        $rawItems = $productList;
                        if (isset($rawItems[$productKey])) {
                            $currentQty = (int) ($rawItems[$productKey]['quantity'] ?? 0);
                            if ($currentQty - $deleteQuantity > 0) {
                                $rawItems[$productKey]['quantity'] -= $deleteQuantity;
                                $price = (float) ($rawItems[$productKey]['price'] ?? 0);
                                $vat = (float) ($rawItems[$productKey]['vat'] ?? 0);
                                $rawItems[$productKey]['TotalPrice'] = ($price + ($price * $vat / 100)) * $rawItems[$productKey]['quantity'];
                            } else {
                                unset($rawItems[$productKey]);
                            }
                        }
                        $dataItem = [
                            'item' => $rawItems,
                            'discountPayment' => $discount,
                            'reasonSurcharge' => $request->input('surcharge_reason', ''),
                            'surcharge' => $surcharge,
                            'total_tax' => $totalTax,
                        ];
                        $newPayload = json_encode($dataItem);
                        $payment->items = $newPayload;
                        $payment->discount = $discount;
                        $payment->surcharge = $surcharge;
                        $payment->tax = $totalTax;
                        $payment->total = $valuetotal > 0 ? $valuetotal : array_sum(array_column($rawItems, 'TotalPrice'));
                        $payment->final_total = max(0, $payment->total - $discount + $surcharge);
                    } else {
                        // No product_list from FE — fallback to decoding payment->items (old path)
                        $items = json_decode($payment->items, true) ?: [];
                        $rawItems = $items['item'] ?? $items ?? [];

                        if (isset($rawItems[$productKey])) {
                            $currentQty = (int) ($rawItems[$productKey]['quantity'] ?? 0);
                            if ($currentQty - $deleteQuantity > 0) {
                                $rawItems[$productKey]['quantity'] -= $deleteQuantity;
                            } else {
                                unset($rawItems[$productKey]);
                            }
                        }
                        $newPayload = json_encode(['item' => $rawItems]);
                        $payment->items = $newPayload;
                    }
                    $payment->save();

                    // Update Table listitem
                    if (!empty($tableId)) {
                        Table::whereKey($tableId)->where('store_id', $storeId)->update([
                            'listitem' => $payment->items,
                        ]);
                    }

                    // Update corresponding PaymentDetail
                    $detail = PaymentDetail::where('payment_id', $payment->id)
                        ->where('product_key', $productKey)
                        ->first();

                    if ($detail) {
                        if ((int) $detail->quantity === $deleteQuantity) {
                            $detail->delete();
                        } else if ($deleteQuantity < (int) $detail->quantity) {
                            $detail->quantity = (int) $detail->quantity - $deleteQuantity;
                            $detail->total = $detail->price * $detail->quantity;
                            $detail->save();
                        }
                    }
                }
                return $payment;
            });

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'Xóa chi tiết hóa đơn thành công',
                'data' => $payment->load('details')->toArray(),
            ]);

        } catch (\Throwable $th) {
            Log::error('Edge delete payment detail failed', ['error' => $th->getMessage()]);
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => 'Lỗi xử lý hệ thống cục bộ',
            ], 500);
        }
    }

    public function getSaleToday(Request $request)
    {
        $language = $request->input('language', 'vi');
        app()->setLocale($language);

        Log::info('Edge getSaleToday: API request received', [
            'store_id_request' => $request->input('store_id'),
            'language' => $language,
            'time' => now()->toDateTimeString()
        ]);

        try {
            $storeId = (int) $request->input('store_id');
            if (!$storeId) {
                $storeId = (int) data_get($request->input('users', []), 'query.store_id');
            }
            if (!$storeId) {
                $storeId = (int) config('app.store_id');
            }
            if (!$storeId) {
                $storeId = (int) config('edge_box.store_id');
            }
            if (!$storeId) {
                $store = Store::first();
                $storeId = $store ? $store->id : 1;
            }

            Log::info('Edge getSaleToday: resolved store_id', ['store_id' => $storeId]);

            $today = now()->toDateString();
            
            // Lấy các payment trong ngày để tính discount
            $paymentInfo = Payment::whereDate('created_at', $today)
                ->where('status', '!=', -1)
                ->where('store_id', $storeId)
                ->get();
                
            Log::info('Edge getSaleToday: fetched active payments for today', [
                'date' => $today,
                'count' => $paymentInfo->count()
            ]);

            $totalDiscount = 0;
            foreach ($paymentInfo as $payment) {
                if ($payment->discount > 0) {
                    $totalDiscount += $payment->discount;
                } else {
                    $tmp = json_decode($payment->items);
                    if (is_object($tmp) && property_exists($tmp, 'discountPayment')) {
                        $discount = str_replace(",", "", $tmp->discountPayment);
                        $totalDiscount += (int)$discount;
                    }
                }
            }

            Log::info('Edge getSaleToday: calculated total discount', ['total_discount' => $totalDiscount]);

            // Lấy danh sách payment methods
            $methods = PaymentMethod::where('store_id', $storeId)->get();
            $methodValues = $methods->pluck('value')->all();
            
            Log::info('Edge getSaleToday: fetched payment methods', [
                'methods_count' => $methods->count(),
                'method_values' => $methodValues
            ]);

            $selectExpressions = [
                DB::raw('COALESCE(SUM(total), 0) as total_amount'),
                DB::raw('COALESCE(SUM(CASE WHEN status = 1 THEN total ELSE 0 END), 0) as paid_amount'),
                DB::raw('COALESCE(SUM(CASE WHEN status = 0 THEN total ELSE 0 END), 0) as unpaid_amount'),
                DB::raw('COALESCE(SUM(CASE WHEN status = -1 THEN total ELSE 0 END), 0) as deleted_amount'),
                DB::raw('COALESCE(count(*), 0) as payment_count'),
                DB::raw('COALESCE(SUM(CASE WHEN status = -1 THEN 1 ELSE 0 END), 0) as payment_deleted_count'),
                DB::raw('COALESCE(SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END), 0) as payment_pending_count'),
                DB::raw('COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as payment_paided_count'),
            ];

            foreach ($methodValues as $val) {
                $valInt = (int)$val;
                $selectExpressions[] = DB::raw("COALESCE(SUM(CASE WHEN payment_method = $valInt AND status = 1 THEN total ELSE 0 END), 0) as payment_method_$valInt");
            }

            // Đảm bảo có default các phương thức 1, 2, 4 nếu FE map tĩnh
            $defaultMethods = [1, 2, 4];
            foreach ($defaultMethods as $valInt) {
                if (!in_array($valInt, $methodValues)) {
                    $selectExpressions[] = DB::raw("COALESCE(SUM(CASE WHEN payment_method = $valInt AND status = 1 THEN total ELSE 0 END), 0) as payment_method_$valInt");
                }
            }

            $saleQuery = Payment::select($selectExpressions)
                ->whereDate('created_at', $today)
                ->where('store_id', $storeId)
                ->first();

            $saleData = $saleQuery ? $saleQuery->toArray() : [];

            // Tính số lượng sản phẩm bán trong ngày
            $productsSoldToday = PaymentDetail::join('payments', 'payment_details.payment_id', '=', 'payments.id')
                ->where('payments.status', '=', 1)
                ->whereDate('payment_details.created_at', $today)
                ->where('payments.store_id', $storeId)
                ->sum('payment_details.quantity');
            
            $totalUnpaidProducts = PaymentDetail::join('payments', 'payment_details.payment_id', '=', 'payments.id')
                ->where('payments.status', '=', 0)
                ->whereDate('payment_details.created_at', $today)
                ->where('payments.store_id', $storeId)
                ->sum('payment_details.quantity');

            $productsCancelledToday = PaymentDetail::join('payments', 'payment_details.payment_id', '=', 'payments.id')
                ->where('payments.status', '=', -1)
                ->whereDate('payment_details.created_at', $today)
                ->where('payments.store_id', $storeId)
                ->sum('payment_details.quantity');

            Log::info('Edge getSaleToday: calculated product metrics', [
                'products_sold' => $productsSoldToday,
                'products_unpaid' => $totalUnpaidProducts,
                'products_cancelled' => $productsCancelledToday
            ]);

            // Map payment method names
            $payment_method_customer = [];
            foreach ($methods as $method) {
                $val = (int)$method->value;
                $payment_method_customer[] = [
                    'id' => (string)$method->id,
                    'name' => $method->name,
                    'total_amount' => $saleData["payment_method_$val"] ?? 0,
                ];
            }

            // Gộp dữ liệu
            $saleData['totalDicount'] = $totalDiscount;
            $saleData['productsSoldToday'] = (string)$productsSoldToday;
            $saleData['productsCancelledToday'] = $productsCancelledToday;
            $saleData['totalUnpaidProducts'] = $totalUnpaidProducts;
            $saleData['payment_method_customer'] = $payment_method_customer;

            Log::info('Edge getSaleToday: API call completed successfully', [
                'total_amount' => $saleData['total_amount'] ?? 0,
                'paid_amount' => $saleData['paid_amount'] ?? 0
            ]);

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.revenue_get'),
                'sale' => $saleData,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Edge getSaleToday failed with exception', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.ISError'),
            ], 500);
        }
    }

    public function listOpen(Request $request)
    {
        app()->setLocale($request->input('isCheckLanguage', 'vi'));
        try {
            $storeId = config('edge_box.store_id') ?? Store::first()?->id ?? 1;
            $payments = Payment::with('details')
                ->where('store_id', $storeId)
                ->where('status', 0)
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json([
                'status' => true,
                'data' => $payments,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Edge listOpen failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'status_code' => 500, 'message' => __('api.ISError')], 500);
        }
    }

    public function getPayment($id)
    {
        try {
            $storeId = config('edge_box.store_id') ?? Store::first()?->id ?? 1;
            $payment = Payment::with('details')->where('store_id', $storeId)->where('id', $id)->first();
            if (!$payment) {
                return response()->json(['status' => false, 'message' => 'Payment not found'], 404);
            }
            return response()->json(['status' => true, 'data' => $payment], 200);
        } catch (\Throwable $th) {
            Log::error('Edge getPayment failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'status_code' => 500, 'message' => __('api.ISError')], 500);
        }
    }

    public function checkIsPrinted(Request $request)
    {
        $paymentId = $request->input('payment_id');
        if (!$paymentId) {
            return response()->json(['status' => false, 'status_code' => 400, 'message' => 'payment_id required'], 400);
        }

        $payment = Payment::find($paymentId);
        if (!$payment) {
            return response()->json(['status' => false, 'status_code' => 404, 'data' => ['is_printed' => false]], 404);
        }

        return response()->json([
            'status' => true,
            'status_code' => 200,
            'data' => [
                'is_printed' => (bool) $payment->is_printed,
            ],
        ]);
    }

    public function getPaymentByRequest(Request $request)
    {
        $id = $request->input('id', $request->input('payment_id'));
        if (!$id) {
            return response()->json(['status' => false, 'status_code' => 400, 'message' => 'Payment ID required'], 400);
        }
        return $this->getPayment($id);
    }

    public function getPaymentByTable(Request $request)
    {
        try {
            $storeId = config('edge_box.store_id') ?? Store::first()?->id ?? 1;
            $tableId = $request->input('table_id', $request->input('id'));
            if (!$tableId) {
                return response()->json(['status' => false, 'status_code' => 400, 'message' => 'Table ID required'], 400);
            }
            $table = \App\Models\Table::with('payment.details')->where('store_id', $storeId)->where('id', $tableId)->first();
            if (!$table || !$table->payment) {
                return response()->json(['status' => false, 'status_code' => 404, 'message' => 'No payment found for table'], 404);
            }
            return response()->json([
                'status' => true,
                'data_table' => [$table->toArray()],
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Edge getPaymentByTable failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'status_code' => 500, 'message' => __('api.ISError')], 500);
        }
    }

    public function getAllPaymentForUserNew(Request $request)
    {
        try {
            $storeId = config('edge_box.store_id') ?? Store::first()?->id ?? 1;
            $payments = Payment::with('details')
                ->where('store_id', $storeId)
                ->where('status', 0)
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json([
                'status' => true,
                'data_table' => $payments->toArray(),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Edge getAllPaymentForUserNew failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'status_code' => 500, 'message' => __('api.ISError')], 500);
        }
    }

    public function getAllPaymentForUserNewPaginate(Request $request)
    {
        try {
            $storeId = config('edge_box.store_id') ?? Store::first()?->id ?? 1;
            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 15);
            $query = $request->input('query', []);

            $paymentsQuery = Payment::with(['user', 'customer', 'details'])
                ->where('store_id', $storeId);

            // Apply filters
            if (!empty($query['user_id'])) {
                $paymentsQuery->where('user_id', $query['user_id']);
            }
            if (!empty($query['customer_id']) || isset($query['customer_id'])) {
                $paymentsQuery->where('customer_id', $query['customer_id']);
            }
            if (!empty($query['payment_method'])) {
                $paymentsQuery->where('payment_method', $query['payment_method']);
            }
            if (isset($query['status']) && $query['status'] !== '' && $query['status'] !== null) {
                $paymentsQuery->where('status', (int) $query['status']);
            }
            if (!empty($query['updated_at']) && is_array($query['updated_at'])) {
                $dates = array_filter($query['updated_at']);
                if (!empty($dates)) {
                    $paymentsQuery->whereDate('updated_at', '>=', $dates[0]);
                    if (isset($dates[1])) {
                        $paymentsQuery->whereDate('updated_at', '<=', $dates[1]);
                    }
                }
            }

            $total = $paymentsQuery->count();
            $totalPages = max(1, ceil($total / $pageSize));
            $payments = $paymentsQuery->orderBy('created_at', 'desc')
                ->skip(($page - 1) * $pageSize)
                ->take($pageSize)
                ->get();

            $payments = $payments->map(function ($p) {
                $data = $p->toArray();
                $data['valuetotal'] = $data['total'] ?? 0;
                $data['reasonSurcharge'] = $data['surcharge_reason'] ?? '';
                $data['user'] = $data['user'] ?? ['id' => 0, 'name' => 'Unknown'];
                $data['customer'] = $data['customer'] ?? null;
                return $data;
            });

            return response()->json([
                'payments' => $payments,
                'currentPage' => $page,
                'total' => $totalPages,
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge getAllPaymentForUserNewPaginate failed', ['error' => $th->getMessage()]);
            return response()->json(['payments' => [], 'currentPage' => 1, 'total' => 1], 200);
        }
    }

    public function getRevenueToDayByAdminId(Request $request)
    {
        $language = $request->input('isCheckLanguage', 'vi');
        app()->setLocale($language);

        Log::info('Edge getRevenueToDayByAdminId: API request received', [
            'store_id_request' => $request->input('store_id'),
            'language' => $language,
            'time' => now()->toDateTimeString()
        ]);

        try {
            $storeId = (int) $request->input('store_id');
            if (!$storeId) {
                $storeId = (int) config('app.store_id');
            }
            if (!$storeId) {
                $storeId = (int) config('edge_box.store_id');
            }
            if (!$storeId) {
                $store = Store::first();
                $storeId = $store ? $store->id : 1;
            }

            Log::info('Edge getRevenueToDayByAdminId: resolved store_id', ['store_id' => $storeId]);

            $today = now()->toDateString();
            
            // Tính tổng total của các payment có status = 1 (đã thanh toán) trong ngày hôm nay
            $totalRevenue = Payment::where('store_id', $storeId)
                ->where('status', 1)
                ->whereDate('created_at', $today)
                ->sum('total');

            Log::info('Edge getRevenueToDayByAdminId completed successfully', [
                'date' => $today,
                'total_revenue' => $totalRevenue
            ]);

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.revenue_get'),
                'revenue' => (float)$totalRevenue,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Edge getRevenueToDayByAdminId failed with exception', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.ISError'),
            ], 500);
        }
    }

    public function getRevenueByDate(Request $request)
    {
        $language = $request->input('isCheckLanguage', 'vi');
        app()->setLocale($language);

        $date = $request->input('date');

        Log::info('Edge getRevenueByDate: API request received', [
            'date' => $date,
            'store_id_request' => $request->input('store_id'),
            'language' => $language,
            'time' => now()->toDateTimeString()
        ]);

        if (empty($date)) {
            return response()->json([
                'status' => false,
                'status_code' => 400,
                'message' => 'Date is required',
            ], 400);
        }

        try {
            $storeId = (int) $request->input('store_id');
            if (!$storeId) {
                $storeId = (int) config('app.store_id');
            }
            if (!$storeId) {
                $storeId = (int) config('edge_box.store_id');
            }
            if (!$storeId) {
                $store = Store::first();
                $storeId = $store ? $store->id : 1;
            }

            Log::info('Edge getRevenueByDate: resolved store_id', ['store_id' => $storeId]);

            // Tính tổng total của các payment có status = 1 (đã thanh toán) trong ngày được chọn
            $totalRevenue = Payment::where('store_id', $storeId)
                ->where('status', 1)
                ->whereDate('created_at', $date)
                ->sum('total');

            Log::info('Edge getRevenueByDate completed successfully', [
                'date' => $date,
                'total_revenue' => $totalRevenue
            ]);

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.revenue_get'),
                'revenue' => (float)$totalRevenue,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Edge getRevenueByDate failed with exception', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.ISError'),
            ], 500);
        }
    }

    public function getRevenueByDateToDate(Request $request)
    {
        $language = $request->input('isCheckLanguage', 'vi');
        app()->setLocale($language);

        $dateStart = $request->input('date_start');
        $dateEnd = $request->input('date_end');

        Log::info('Edge getRevenueByDateToDate: API request received', [
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'store_id_request' => $request->input('store_id'),
            'language' => $language,
            'time' => now()->toDateTimeString()
        ]);

        if (empty($dateStart) || empty($dateEnd)) {
            return response()->json([
                'status' => false,
                'status_code' => 400,
                'message' => 'date_start and date_end are required',
            ], 400);
        }

        try {
            $storeId = (int) $request->input('store_id');
            if (!$storeId) {
                $storeId = (int) config('app.store_id');
            }
            if (!$storeId) {
                $storeId = (int) config('edge_box.store_id');
            }
            if (!$storeId) {
                $store = Store::first();
                $storeId = $store ? $store->id : 1;
            }

            Log::info('Edge getRevenueByDateToDate: resolved store_id', ['store_id' => $storeId]);

            // Tính tổng total của các payment có status = 1 (đã thanh toán) trong khoảng ngày được chọn
            $totalRevenue = Payment::where('store_id', $storeId)
                ->where('status', 1)
                ->whereDate('created_at', '>=', $dateStart)
                ->whereDate('created_at', '<=', $dateEnd)
                ->sum('total');

            Log::info('Edge getRevenueByDateToDate completed successfully', [
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'total_revenue' => $totalRevenue
            ]);

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.revenue_get'),
                'revenue' => (float)$totalRevenue,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Edge getRevenueByDateToDate failed with exception', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.ISError'),
            ], 500);
        }
    }
}

