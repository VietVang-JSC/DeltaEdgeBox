<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Payment;
use App\Models\PaymentDetail;
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
                    'total' => $request->input('valuetotal', $summary['total']),
                    'discount' => (float) $request->input('discount', 0),
                    'tax' => (float) $request->input('total_tax', 0),
                    'final_total' => $request->input('amount_received', $request->input('valuetotal', $summary['total'])),
                    'payment_method' => $request->input('payment_method', 'cash') ?: 'cash',
                    'note' => $request->input('reason'),
                    'status' => $status,
                    'user_id' => $userId,
                    'admin_id' => $request->input('admin_id', $userId),
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

            app()->terminating(function () {
                try {
                    app(SyncService::class)->processQueue(10);
                } catch (\Throwable $th) {
                    Log::warning('Edge payment post-response sync failed', [
                        'error' => $th->getMessage(),
                    ]);
                }
            });

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'api.payment_create',
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
                'message' => 'api.ISError',
            ], 500);
        }
    }

    public function updatePayment(Request $request)
    {
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
                    'total' => $request->input('valuetotal', $summary['total']),
                    'discount' => (float) $request->input('discount', 0),
                    'surcharge' => (float) $request->input('surcharge', 0),
                    'surcharge_reason' => $request->input('surcharge_reason'),
                    'surcharge_percent' => (int) $request->input('surcharge_percent', 0),
                    'service_charge' => (int) $request->input('service_charge', 0),
                    'tax' => (float) $request->input('total_tax', 0),
                    'final_total' => $request->input('amount_received', $request->input('valuetotal', $summary['total'])),
                    'payment_method' => $request->input('payment_method', $payment->payment_method ?: 'cash'),
                    'note' => $request->input('reason'),
                    'status' => $status,
                    'user_id' => $userId,
                    'admin_id' => $request->input('admin_id', $payment->admin_id ?: $userId),
                    'type_discount' => $request->input('type_discount', 'amount'),
                    'discount_percent' => (int) $request->input('discount_percent', 0),
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

            app()->terminating(function () {
                try {
                    app(SyncService::class)->processQueue(10);
                } catch (\Throwable $th) {
                    Log::warning('Edge payment update post-response sync failed', [
                        'error' => $th->getMessage(),
                    ]);
                }
            });

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'api.payment_update',
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
                'message' => 'api.ISError',
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

            $payment = DB::transaction(function () use ($payment, $productKey, $deleteQuantity, $deletePayment, $tableId, $storeId) {
                if ($deletePayment) {
                    // Update table status if set
                    if (!empty($tableId)) {
                        $this->clearTableAfterPayment($tableId, $storeId);
                    }
                    
                    // Soft/Hard delete payment
                    $payment->status = -1;
                    $payment->save();
                    
                    $payment->details()->delete();
                    $payment->delete();
                } else {
                    // Part-delete quantity
                    $items = json_decode($payment->items, true) ?: [];
                    $rawItems = $items['item'] ?? $items ?? [];

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

                    $newPayload = json_encode(['item' => array_values($rawItems)]);
                    
                    // Recalculate totals
                    $decodedItems = $this->decodeItems($newPayload);
                    $summary = $this->summarizeItems($decodedItems);

                    $payment->items = $newPayload;
                    $payment->total = $summary['total'];
                    $payment->final_total = max(0, $summary['total'] - (float) $payment->discount + (float) $payment->surcharge);
                    $payment->save();

                    // Update Table item list
                    if (!empty($tableId)) {
                        Table::whereKey($tableId)->where('store_id', $storeId)->update([
                            'listitem' => $newPayload,
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
}
