<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\Store;
use App\Models\Table;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    private const STATUS_TABLE_ACTIVE = 1;
    private const STATUS_PAYMENT_ACTIVE = 1;
    private const STATUS_PAYMENT_PENDING = 0;

    private function setRequestLocale(Request $request)
    {
        app()->setLocale($request->header('Accept-Language', $request->input('isCheckLanguage', 'vi')));
    }

    private function resolvePaymentMethodName($paymentMethod, $storeId)
    {
        if ($paymentMethod === null || $paymentMethod === '') {
            return null;
        }

        if (!is_numeric($paymentMethod)) {
            return $paymentMethod;
        }

        $methodValue = (int) $paymentMethod;
        $method = PaymentMethod::where('value', $methodValue)
            ->where('store_id', $storeId)
            ->first();
        if ($method) {
            return $method->name;
        }

        if (PaymentStatus::where('value', $methodValue)->exists()) {
            return __('api.payment_status.' . $methodValue);
        }

        return $paymentMethod;
    }
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
            $payment = DB::transaction(function () use ($request) {
                $status = (int) $request->input('status', self::STATUS_PAYMENT_ACTIVE);
                // If no table_id and no payment_method, this is a temp invoice — force pending
                $tableId = $request->input('table_id', $request->input('tableID'));
                if ($status === self::STATUS_PAYMENT_ACTIVE && empty($tableId) && empty($request->input('payment_method'))) {
                    $status = self::STATUS_PAYMENT_PENDING;
                }
                $storeId = (int) $request->input('store_id', config('edge_box.store_id') ?? config('app.store_id'));
                $userId = (int) $request->input('user_id', 1);
                $paymentTime = $this->storeNow($storeId);
                $calculation = $this->buildCalculatedPaymentData($request->input('items'), $storeId, $request->all());

                $payment = Payment::create([
                    'payment_code' => $request->input('payment_code') ?: 'EDGE-' . $paymentTime->format('YmdHis') . '-' . random_int(1000, 9999),
                    'store_id' => $storeId,
                    'table_id' => $tableId,
                    'customer_id' => $request->input('customer_id'),
                    'items' => $calculation['items_payload'],
                    'paid_date' => $paymentTime,
                    'total' => $calculation['total'],
                    'discount' => $calculation['discount'],
                    'tax' => $calculation['tax'],
                    'final_total' => $calculation['final_total'],
                    'amount_received' => $request->input('amount_received') !== null && $request->input('amount_received') !== ''
                        ? round((float) $request->input('amount_received'))
                        : null,
                    'payment_method' => (int) ($request->input('payment_method', 1) ?: 1),
                    'note' => $request->input('reason'),
                    'reason' => $request->input('reason'),
                    'status' => $status,
                    'user_id' => $userId,
                    'admin_id' => $request->input('admin_id', $userId),
                    'surcharge' => $calculation['surcharge'],
                    'surcharge_reason' => $calculation['surcharge_reason'],
                    'surcharge_percent' => $calculation['surcharge_percent'],
                    'service_charge' => $calculation['service_charge'],
                    'service_charge_amount' => $calculation['service_charge_amount'],
                    'type_discount' => $calculation['type_discount'],
                    'discount_percent' => $calculation['discount_percent'],
                    'is_senior_discount' => $calculation['is_senior_discount'],
                    'senior_discount_amount' => $calculation['senior_discount_amount'],
                    'sub_total_before_discount' => $calculation['sub_total_before_discount'],
                    'total_incl_vat_before_discount' => $calculation['total_incl_vat_before_discount'],
                    'created_at' => $paymentTime,
                    'updated_at' => $paymentTime,
                ]);

                foreach ($calculation['items'] as $item) {
                    PaymentDetail::create($this->buildPaymentDetailAttributes(
                        $payment,
                        $item,
                        $storeId,
                        (int) $request->input('admin_id', $userId),
                        $paymentTime
                    ));
                }

                if ($status === self::STATUS_PAYMENT_ACTIVE) {
                    $this->deductInventoryForPayment($payment, array_values($calculation['items']), $storeId, $userId);
                    $this->clearTableAfterPayment($tableId, $storeId);
                }

                return $payment->load('details');
            });

            // Sync runs via background SyncWorker — no blocking needed

            Log::info('EDGE BOX: Payment created successfully', [
                'payment_id' => $payment->id,
                'payment_code' => $payment->payment_code,
                'final_total' => $payment->final_total,
                'table_id' => $payment->table_id,
            ]);

            $paymentArray = $payment->toArray();
            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.payment_create'),
                'paymentInfo' => $paymentArray,
                'data' => [
                    'payment' => $paymentArray,
                ],
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge payment create failed', [
                'error' => $th->getMessage(),
            ]);

            $isStock = str_contains($th->getMessage(), 'Inventory insufficient');
            return response()->json([
                'status' => false,
                'status_code' => $isStock ? 409 : 500,
                'message' => $isStock ? __('api.insufficient_stock') : __('api.ISError'),
            ], $isStock ? 409 : 500);
        }
    }

    public function updatePayment(Request $request)
    {
        app()->setLocale($request->input('isCheckLanguage', 'vi'));

        $paymentId = $request->input('id', $request->input('payment_id'));

        // If no payment_id provided, create new payment instead of updating
        if (!$paymentId) {
            return $this->createPayment($request);
        }

        try {
            $payment = DB::transaction(function () use ($request) {
                $storeId = (int) $request->input('store_id', config('edge_box.store_id') ?? config('app.store_id'));
                $paymentId = $request->input('id', $request->input('payment_id'));

                if (!$paymentId) {
                    return $this->createPayment($request);
                }

                $payment = Payment::whereKey($paymentId)->where('store_id', $storeId)->lockForUpdate()->first();
                if (!$payment) {
                    $payment = Payment::whereKey($paymentId)->lockForUpdate()->first();
                }
                if (!$payment) {
                    throw new \RuntimeException('Payment not found');
                }

                $oldStatus = (int) $payment->status;
                $requestedStatus = (int) $request->input('status', $oldStatus);
                if ($requestedStatus === self::STATUS_PAYMENT_ACTIVE && $payment->table_id === null && !$request->has('table_id') && !$request->has('payment_method') && $request->input('amount_received', 0) == 0) {
                    $status = $oldStatus;
                } else {
                    $status = $requestedStatus;
                }
                $userId = (int) $request->input('user_id', $payment->user_id ?: 1);
                $paymentTime = $this->storeNow($storeId);

                $updates = [];

                // Common fields: always update status, payment_method, amount_received if present
                $updates['status'] = $status;
                $updates['updated_at'] = $paymentTime;
                if ($status === self::STATUS_PAYMENT_ACTIVE && $request->has('payment_method')) {
                    $updates['payment_method'] = (int) $request->input('payment_method');
                }
                if ($request->input('amount_received') !== null && $request->input('amount_received') !== '') {
                    $updates['amount_received'] = round((float) $request->input('amount_received'));
                }
                if ($status === self::STATUS_PAYMENT_ACTIVE) {
                    $updates['paid_date'] = $paymentTime;
                }
                if ($request->has('final_total') || $request->has('valuetotal')) {
                    $updates['final_total'] = (float) ($request->input('final_total', $request->input('valuetotal')));
                    $updates['total'] = $updates['final_total'];
                }

                // If items provided, do full recalculation
                $itemsInput = $request->input('items');
                if (!empty($itemsInput)) {
                    $calculation = $this->buildCalculatedPaymentData($itemsInput, $storeId, $request->all());
                    $tableId = $request->input('table_id');
                    if ($tableId === null && $payment->table_id !== null) {
                        $tableId = $payment->table_id;
                    }
                    $updates['table_id'] = $tableId;
                    $updates['customer_id'] = $request->input('customer_id', $payment->customer_id);
                    $updates['items'] = $calculation['items_payload'];
                    $updates['total'] = $calculation['total'];
                    $updates['discount'] = $calculation['discount'];
                    $updates['surcharge'] = $calculation['surcharge'];
                    $updates['surcharge_reason'] = $calculation['surcharge_reason'];
                    $updates['surcharge_percent'] = $calculation['surcharge_percent'];
                    $updates['service_charge'] = $calculation['service_charge'];
                    $updates['service_charge_amount'] = $calculation['service_charge_amount'];
                    $updates['tax'] = $calculation['tax'];
                    $updates['final_total'] = $calculation['final_total'];
                    $updates['type_discount'] = $calculation['type_discount'];
                    $updates['discount_percent'] = $calculation['discount_percent'];
                    $updates['is_senior_discount'] = $calculation['is_senior_discount'];
                    $updates['senior_discount_amount'] = $calculation['senior_discount_amount'];
                    $updates['sub_total_before_discount'] = $calculation['sub_total_before_discount'];
                    $updates['total_incl_vat_before_discount'] = $calculation['total_incl_vat_before_discount'];
                    if ($request->has('payment_method')) {
                        $updates['payment_method'] = (int) $request->input('payment_method');
                    }
                }

                if (empty($itemsInput)) {
                    if ($request->has('is_senior_discount')) {
                        $updates['is_senior_discount'] = filter_var($request->input('is_senior_discount'), FILTER_VALIDATE_BOOLEAN);
                    }
                    if ($request->has('senior_discount_amount')) {
                        $updates['senior_discount_amount'] = (float) $request->input('senior_discount_amount');
                    }
                }

                $updates['note'] = $request->input('reason', $payment->note);
                $updates['reason'] = $request->input('reason', $payment->reason);
                $updates['user_id'] = $userId;
                $updates['admin_id'] = $request->input('admin_id', $payment->admin_id ?: $userId);

                $payment->fill($updates);
                $payment->save();

                if (!empty($itemsInput) && isset($calculation)) {
                    $payment->details()->delete();
                    foreach ($calculation['items'] as $item) {
                        PaymentDetail::create($this->buildPaymentDetailAttributes(
                            $payment,
                            $item,
                            $storeId,
                            (int) $request->input('admin_id', $payment->admin_id ?: $userId),
                            $paymentTime
                        ));
                    }

                    if ($oldStatus !== self::STATUS_PAYMENT_ACTIVE && $status === self::STATUS_PAYMENT_ACTIVE) {
                        $this->deductInventoryForPayment($payment, array_values($calculation['items']), $storeId, $userId);
                        $this->clearTableAfterPayment($payment->table_id, $storeId);
                    }
                }

                return $payment->load('details');
            });

            try {
                app(SyncService::class)->processQueue(10);
            } catch (\Throwable $th) {
                Log::warning('Edge payment update sync failed', ['error' => $th->getMessage()]);
            }

            Log::info('EDGE BOX: Payment updated successfully', [
                'payment_id' => $payment->id,
                'payment_code' => $payment->payment_code,
                'final_total' => $payment->final_total,
                'table_id' => $payment->table_id,
            ]);

            $paymentArray = $payment->toArray();
            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.payment_update'),
                'paymentInfo' => $paymentArray,
                'data' => [
                    'payment' => $paymentArray,
                ],
            ]);
        } catch (\RuntimeException $th) {
            $isStock = str_contains($th->getMessage(), 'Inventory insufficient');
            return response()->json([
                'status' => false,
                'status_code' => $isStock ? 409 : 404,
                'message' => $isStock ? __('api.insufficient_stock') : $th->getMessage(),
            ], $isStock ? 409 : 404);
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
        $storeTz = Store::whereKey($storeId)->value('time_zone');
        return $storeTz ? now($storeTz) : now();
    }

    private function buildCalculatedPaymentData($itemsInput, int $storeId, array $input): array
    {
        $rawItems = $this->decodeRawItems($itemsInput);
        $store = Store::whereKey($storeId)->first();
        $isTaxIncluded = (bool) ($store->is_tax_included ?? false);
        
        $typeDiscount = ($input['type_discount'] ?? 'amount') ?: 'amount';
        $discountValue = $typeDiscount === 'percent'
            ? (float) ($input['discount_percent'] ?? $input['discount'] ?? 0)
            : (float) ($input['discount'] ?? 0);

        $bill = $isTaxIncluded
            ? $this->allocateDiscountTaxIncluded($rawItems, $discountValue, $typeDiscount)
            : $this->allocateDiscountTaxExcluded($rawItems, $discountValue, $typeDiscount);

        $items = $bill['items'];
        $summary = $bill['summary'];
        
        $taxTotal = (float) ($summary['total_vat'] ?? 0);
        $subTotalBeforeDiscount = round($summary['subtotal_before'] ?? 0);
        $discountTotal = (float) ($summary['discount_total'] ?? 0);
        $totalInclVatBeforeDiscount = (float) ($summary['total_incl_vat_before_discount'] ?? 0);

        $surcharge = (float) ($input['surcharge'] ?? 0);
        $surchargeReason = $input['surcharge_reason'] ?? $input['reasonSurcharge'] ?? null;
        $surchargePercent = $this->nullableNumber($input['surcharge_percent'] ?? null);

        $serviceCharge = (float) ($input['service_charge'] ?? 0);
        $serviceChargeAmount = (float) ($input['service_charge_amount'] ?? 0);
        
        $seniorDiscount = filter_var($input['is_senior_discount'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $seniorDiscountAmount = (float) ($input['senior_discount_amount'] ?? 0);
        if (!$seniorDiscount) {
            $seniorDiscountAmount = 0;
        }

        // Cloud logic: $filters['valuetotal'] = round($billItem['summary']['total_with_vat'] + ($filters['surcharge'] ?? 0));
        $valueTotal = round(($summary['total_with_vat'] ?? 0) + $surcharge);

        if (($store->time_zone ?? config('app.timezone')) === 'Asia/Manila') {
            
            if (!array_key_exists('service_charge', $input) && isset($store->service_charge)) {
                $serviceCharge = (float) $store->service_charge;
            }

            $baseForSurcharge = $isTaxIncluded
                ? (float) ($summary['total_with_vat'] ?? 0)
                : (float) ($summary['subtotal_after'] ?? ($summary['total_with_vat'] ?? 0));

            if ($surchargePercent !== null) {
                $surcharge = round($baseForSurcharge * $surchargePercent / 100);
            }

            $serviceChargeAmount = round($baseForSurcharge * $serviceCharge / 100);

            $valueTotal = round(($summary['total_with_vat'] ?? 0) + $serviceChargeAmount + $surcharge);

            if ($seniorDiscount) {
                $rate = (float) config('params.senior_discount.rate', 20);
                $seniorDiscountAmount = round($subTotalBeforeDiscount * $rate / 100);
                $afterSenior = $subTotalBeforeDiscount - $seniorDiscountAmount;

                if ($typeDiscount === 'percent') {
                    $discountTotal = round($afterSenior * $discountValue / 100);
                }

                $totalAfterDiscount = max(0, $afterSenior - $discountTotal);
                $taxTotal = 0;
                foreach ($items as &$item) {
                    $item['tax_amount'] = 0;
                }
                unset($item);

                $serviceChargeAmount = round(max(0, $totalAfterDiscount) * $serviceCharge / 100);

                if ($surchargePercent !== null) {
                    $surcharge = round(max(0, $totalAfterDiscount) * $surchargePercent / 100);
                }

                $valueTotal = round(max(0, $totalAfterDiscount) + $serviceChargeAmount + $surcharge);
            }
        }

        $payload = [
            'item' => $items,
            'discountPayment' => $discountTotal,
            'reasonSurcharge' => $surchargeReason,
            'surcharge' => $surcharge,
            'total_tax' => $taxTotal,
        ];

        return [
            'items' => $items,
            'items_payload' => json_encode($payload),
            'total' => $valueTotal,
            'discount' => $discountTotal,
            'tax' => $taxTotal,
            'final_total' => $valueTotal,
            'surcharge' => $surcharge,
            'surcharge_reason' => $surchargeReason,
            'surcharge_percent' => $surchargePercent ?? 0,
            'service_charge' => $serviceCharge,
            'service_charge_amount' => $serviceChargeAmount,
            'type_discount' => $typeDiscount,
            'discount_percent' => $typeDiscount === 'percent' ? (int) $discountValue : 0,
            'is_senior_discount' => $seniorDiscount,
            'senior_discount_amount' => $seniorDiscountAmount,
            'sub_total_before_discount' => $subTotalBeforeDiscount,
            'total_incl_vat_before_discount' => $totalInclVatBeforeDiscount,
        ];
    }

    private function decodeRawItems($itemsInput): array
    {
        $decoded = is_string($itemsInput) ? json_decode($itemsInput, true) : $itemsInput;
        $rawItems = $decoded['item'] ?? $decoded ?? [];
        $items = [];

        foreach ($rawItems as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $item['product_id'] ?? $item['id'] ?? null;
            if (empty($productId)) {
                continue;
            }

            $productKey = $item['product_key'] ?? $item['key'] ?? (is_string($key) ? $key : null);
            if (empty($productKey)) {
                $productKey = (string) $productId;
            }

            $quantity = max(0, (int) ($item['quantity'] ?? 1));
            if ($quantity <= 0) {
                continue;
            }

            $price = (float) ($item['price'] ?? 0);
            $vat = (float) ($item['vat'] ?? 0);
            $item['id'] = (int) $productId;
            $item['product_id'] = (int) $productId;
            $item['product_key'] = $productKey;
            $item['quantity'] = $quantity;
            $item['price'] = $price;
            $item['vat'] = $vat;
            $item['TotalPrice'] = (float) ($item['TotalPrice'] ?? $item['total'] ?? ($price * $quantity));
            $items[$productKey] = $item;
        }

        return $items;
    }

    private function allocateDiscountTaxExcluded(array $items, float $discountValue, string $typeDiscount = 'amount'): array
    {
        $totalBase = 0;
        foreach ($items as $item) {
            $totalBase += (float) $item['price'] * (int) $item['quantity'];
        }

        if ($totalBase <= 0) {
            return ['items' => [], 'summary' => $this->emptyBillSummary()];
        }

        $allocatedSum = 0;
        $lastKey = array_key_last($items);

        foreach ($items as $key => &$item) {
            $subTotal = (float) $item['price'] * (int) $item['quantity'];
            $item['sub_total_excl_vat'] = round($subTotal);

            if ($typeDiscount === 'percent') {
                $item['discount_percent'] = $discountValue;
                $item['discount_allocated_excl_vat'] = round($subTotal * $discountValue / 100);
            } else {
                $ratio = $subTotal / $totalBase;
                if ($key !== $lastKey) {
                    $item['discount_allocated_excl_vat'] = round($discountValue * $ratio);
                    $allocatedSum += $item['discount_allocated_excl_vat'];
                } else {
                    $item['discount_allocated_excl_vat'] = $discountValue - $allocatedSum;
                }
            }

            $item['net_excl_vat'] = $item['sub_total_excl_vat'] - $item['discount_allocated_excl_vat'];
            $item['tax_amount'] = round($item['net_excl_vat'] * ((float) $item['vat'] / 100));
            $item['total_with_vat_after_discount'] = $item['net_excl_vat'] + $item['tax_amount'];
            $item['detail_discount'] = $item['discount_allocated_excl_vat'];
            $item['TotalPrice'] = round($item['sub_total_excl_vat'] * (1 + ((float) $item['vat'] / 100)));
            $item['detail_discount_excluding_tax'] = $item['discount_allocated_excl_vat'];
            $item['unit_price_excluding_tax'] = (float) $item['price'];
            $item['discounted_price_excluding_tax'] = $item['net_excl_vat'];
        }
        unset($item);

        return [
            'items' => $items,
            'summary' => [
                'subtotal_before' => array_sum(array_column($items, 'sub_total_excl_vat')),
                'discount_total' => $typeDiscount === 'percent' ? round($totalBase * $discountValue / 100) : $discountValue,
                'subtotal_after' => array_sum(array_column($items, 'net_excl_vat')),
                'total_vat' => array_sum(array_column($items, 'tax_amount')),
                'total_with_vat' => array_sum(array_column($items, 'total_with_vat_after_discount')),
                'total_incl_vat_before_discount' => array_sum(array_column($items, 'TotalPrice')),
            ],
        ];
    }

    private function allocateDiscountTaxIncluded(array $items, float $discountValue, string $typeDiscount = 'amount'): array
    {
        $totalWithVat = 0;
        foreach ($items as $item) {
            $totalWithVat += (float) $item['price'] * (int) $item['quantity'];
        }

        if ($totalWithVat <= 0) {
            return ['items' => [], 'summary' => $this->emptyBillSummary()];
        }

        $allocatedSum = 0;
        $lastKey = array_key_last($items);

        foreach ($items as $key => &$item) {
            $vatRate = (float) $item['vat'];
            $vatDivisor = 1 + ($vatRate / 100);
            $subTotalInclVat = (float) $item['price'] * (int) $item['quantity'];
            $item['sub_total_incl_vat'] = round($subTotalInclVat);

            if ($typeDiscount === 'percent') {
                $item['discount_percent'] = $discountValue;
                $item['discount_allocated_incl_vat'] = round($subTotalInclVat * $discountValue / 100);
            } else {
                $ratio = $subTotalInclVat / $totalWithVat;
                if ($key !== $lastKey) {
                    $item['discount_allocated_incl_vat'] = round($discountValue * $ratio);
                    $allocatedSum += $item['discount_allocated_incl_vat'];
                } else {
                    $item['discount_allocated_incl_vat'] = $discountValue - $allocatedSum;
                }
            }

            $item['unit_price_excluding_tax'] = round((float) $item['price'] / $vatDivisor);
            $item['discount_allocated_excl_vat'] = round($item['discount_allocated_incl_vat'] / $vatDivisor);
            $item['total_with_vat_after_discount'] = $item['sub_total_incl_vat'] - $item['discount_allocated_incl_vat'];
            $item['net_excl_vat'] = round($item['total_with_vat_after_discount'] / $vatDivisor);
            $item['tax_amount'] = $item['total_with_vat_after_discount'] - $item['net_excl_vat'];
            $item['detail_discount'] = $item['discount_allocated_incl_vat'];
            $item['TotalPrice'] = $item['sub_total_incl_vat'];
            $item['detail_discount_excluding_tax'] = $item['discount_allocated_excl_vat'];
            $item['discounted_price_excluding_tax'] = $item['net_excl_vat'];
        }
        unset($item);

        return [
            'items' => $items,
            'summary' => [
                'subtotal_before' => array_sum(array_map(function ($item) {
                    return $item['sub_total_incl_vat'] / (1 + ((float) $item['vat'] / 100));
                }, $items)),
                'discount_total' => $typeDiscount === 'percent' ? round($totalWithVat * $discountValue / 100) : $discountValue,
                'subtotal_after' => array_sum(array_column($items, 'net_excl_vat')),
                'total_vat' => array_sum(array_column($items, 'tax_amount')),
                'total_with_vat' => array_sum(array_column($items, 'total_with_vat_after_discount')),
                'total_incl_vat_before_discount' => array_sum(array_column($items, 'TotalPrice')),
            ],
        ];
    }

    private function emptyBillSummary(): array
    {
        return [
            'subtotal_before' => 0,
            'discount_total' => 0,
            'subtotal_after' => 0,
            'total_vat' => 0,
            'total_with_vat' => 0,
            'total_incl_vat_before_discount' => 0,
        ];
    }

    private function nullableNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function buildPaymentDetailAttributes(Payment $payment, array $item, int $storeId, int $adminId, $timestamp): array
    {
        return [
            'payment_id' => $payment->id,
            'product_id' => $item['product_id'],
            'product_key' => $item['product_key'] ?? null,
            'quantity' => (int) $item['quantity'],
            'price' => (float) $item['price'],
            'total' => (float) ($item['TotalPrice'] ?? $item['total'] ?? 0),
            'note' => $this->buildItemNote($item),
            'product_extra' => !empty($item['extra_product_list']) ? json_encode($item['extra_product_list']) : ($item['product_extra'] ?? null),
            'optional_products' => !empty($item['optional_products']) ? json_encode($item['optional_products']) : null,
            'inventory_histories' => !empty($item['inventory_histories']) ? json_encode($item['inventory_histories']) : null,
            'input_code' => $item['input_code'] ?? null,
            'admin_id' => $adminId,
            'store_id' => $storeId,
            'debt' => (float) ($item['debt'] ?? 0),
            'status' => $item['status'] ?? null,
            'detail_discount' => (float) ($item['detail_discount'] ?? 0),
            'served' => filter_var($item['served'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'tax_amount' => (float) ($item['tax_amount'] ?? 0),
            'detail_discount_excluding_tax' => (float) ($item['detail_discount_excluding_tax'] ?? 0),
            'unit_price_excluding_tax' => (float) ($item['unit_price_excluding_tax'] ?? 0),
            'discounted_price_excluding_tax' => (float) ($item['discounted_price_excluding_tax'] ?? 0),
            'printed_quantity' => (int) ($item['printed_quantity'] ?? 0),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function buildItemNote(array $item): ?string
    {
        $parts = [];
        if (!empty($item['note'])) {
            $parts[] = $item['note'];
        }
        if (!empty($item['product_types']) && is_array($item['product_types'])) {
            foreach ($item['product_types'] as $pt) {
                if (!empty($pt['productTypeValue'])) {
                    $parts[] = $pt['productTypeValue'];
                }
            }
        }
        return !empty($parts) ? implode(', ', $parts) : null;
    }

    private function applyDetailQuantityRatio(PaymentDetail $detail, int $targetQuantity, int $originalQuantity): void
    {
        $ratio = $targetQuantity / max(1, $originalQuantity);
        foreach ([
            'total',
            'detail_discount',
            'tax_amount',
            'detail_discount_excluding_tax',
            'discounted_price_excluding_tax',
        ] as $field) {
            $detail->{$field} = round((float) $detail->{$field} * $ratio, 4);
        }
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
            if ($product && (int) ($product->inventory_required ?? 0) === 0) {
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

    public function deletePaymentForUser(Request $request)
    {
        app()->setLocale($request->input('isCheckLanguage', 'vi'));
        $paymentId = $request->input('data.id');
        $reason = $request->input('data.reason', '');
        if (!$paymentId) {
            return response()->json(['status' => false, 'message' => __('api.missing_payment_id')], 400);
        }
        $payment = Payment::find($paymentId);
        if (!$payment) {
            return response()->json(['status' => false, 'message' => __('api.payment_not_found')], 404);
        }
        $payment->status = -1;
        $payment->reason = $reason;
        $payment->save();
        $payment->details()->update(['delete_note' => $reason]);
        $payment->details()->delete();
        $payment->delete();
        return response()->json(['status' => true, 'message' => __('api.payment_deleted')]);
    }

    public function deletePaymentDetail(Request $request)
    {
        app()->setLocale($request->input('isCheckLanguage', 'vi'));
        $validator = Validator::make($request->all(), [
            'payment_id' => ['required'],
            'product_key' => ['required'],
            'delete_note' => ['required'],
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
            $storeId = (int) $request->input('store_id', config('edge_box.store_id') ?? config('app.store_id', 1));
            $deleteNote = (string) $request->input('delete_note', '');
            $surchargeReason = (string) $request->input('surcharge_reason', '');

            Log::info('Edge delete payment detail request', [
                'payment_id' => $paymentId,
                'product_key' => $productKey,
                'table_id' => $tableId,
                'store_id' => $storeId,
                'delete_quantity' => $deleteQuantity,
                'delete_payment' => $deletePayment,
                'has_product_list' => $request->has('product_list'),
                'product_list_count' => is_array($request->input('product_list')) ? count($request->input('product_list')) : null,
            ]);

            $table = null;
            if (!empty($tableId)) {
                $table = Table::whereKey($tableId)->first();
                if ($table && !$request->filled('store_id')) {
                    $storeId = (int) ($table->store_id ?: $storeId);
                }
            }

            $payment = Payment::whereKey($paymentId)->where('store_id', $storeId)->first();
            // Fallback: try without store_id (for order-new page where no table_id is sent)
            if (!$payment) {
                $payment = Payment::whereKey($paymentId)->first();
                if ($payment) {
                    $storeId = (int) $payment->store_id;
                }
            }
            if (!$payment && !empty($tableId)) {
                if ($table && !empty($table->payment_id)) {
                    $payment = Payment::whereKey($table->payment_id)->where('store_id', $storeId)->first();
                }

                if (!$payment) {
                    $payment = Payment::where('table_id', $tableId)
                        ->where('store_id', $storeId)
                        ->whereNull('deleted_at')
                        ->orderByDesc('id')
                        ->first();
                }
            }

            if (!$payment) {
                // Payment might be soft-deleted — clear table listitem if table_id known
                $trashed = Payment::whereKey($paymentId)->withTrashed()->first();
                if ($trashed && $trashed->table_id) {
                    Table::whereKey($trashed->table_id)->where('store_id', $storeId)->update([
                        'status' => 1,
                        'payment_id' => null,
                        'listitem' => null,
                        'user_id' => null,
                        'lock_time' => null,
                        'number_of_people' => 0,
                    ]);
                }
                return response()->json([
                    'status' => true,
                    'status_code' => 200,
                    'message' => __('api.payment_detail_deleted'),
                ]);
            }

            Log::info('Edge delete payment detail resolved payment', [
                'requested_payment_id' => $paymentId,
                'resolved_payment_id' => $payment->id,
                'table_id' => $tableId,
                'store_id' => $storeId,
            ]);

            $productList = $request->input('product_list');
            $discount = (float) $request->input('discount', 0);
            $surcharge = (float) $request->input('surcharge', 0);
            $totalTax = (float) $request->input('total_tax', 0);
            $valuetotal = (float) $request->input('valuetotal', 0);
            $calculationInput = [
                'discount' => $payment->discount ?? $discount,
                'type_discount' => $payment->type_discount ?? 'amount',
                'discount_percent' => $payment->discount_percent ?? 0,
                'surcharge' => $surcharge,
                'surcharge_reason' => $payment->surcharge_reason ?? $surchargeReason,
                'surcharge_percent' => $payment->surcharge_percent ?? null,
                'service_charge' => $payment->service_charge ?? 0,
                'service_charge_amount' => $payment->service_charge_amount ?? 0,
                'is_senior_discount' => $payment->is_senior_discount ?? false,
                'senior_discount_amount' => $payment->senior_discount_amount ?? 0,
                'amount_received' => $request->input('amount_received'),
            ];

            if ($request->has('product_list') && empty($productList)) {
                $deletePayment = true;
            }

            $payment = DB::transaction(function () use ($payment, $productKey, $deleteQuantity, $deletePayment, $tableId, $storeId, $productList, $discount, $surcharge, $totalTax, $valuetotal, $deleteNote, $surchargeReason, $calculationInput) {
                // Lock payment to prevent concurrent overwrites
                $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

                $detail = PaymentDetail::where('payment_id', $payment->id)
                    ->where('product_key', $productKey)
                    ->where(function ($query) use ($storeId) {
                        $query->where('store_id', $storeId)
                            ->orWhereNull('store_id');
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$detail) {
                    // No payment_detail — remove from items JSON, recalculate via buildCalculatedPaymentData
                    $currentItems = json_decode($payment->items, true) ?: [];
                    if (!isset($currentItems['item'][$productKey])) {
                        throw new \RuntimeException('payment_detail_not_found');
                    }
                    unset($currentItems['item'][$productKey]);
                    if (!empty($currentItems['item'])) {
                        $calculation = $this->buildCalculatedPaymentData($currentItems['item'], $storeId, $calculationInput);
                        $payment->items = $calculation['items_payload'];
                        $payment->discount = $calculation['discount'];
                        $payment->surcharge = $calculation['surcharge'];
                        $payment->surcharge_reason = $calculation['surcharge_reason'];
                        $payment->tax = $calculation['tax'];
                        $payment->total = $calculation['total'];
                        $payment->final_total = $calculation['final_total'];
                        $payment->service_charge = $calculation['service_charge'];
                        $payment->service_charge_amount = $calculation['service_charge_amount'];
                        $payment->sub_total_before_discount = $calculation['sub_total_before_discount'];
                        $payment->total_incl_vat_before_discount = $calculation['total_incl_vat_before_discount'];
                    } else {
                        $payment->items = json_encode($currentItems);
                        $payment->total = 0;
                        $payment->final_total = 0;
                        $payment->tax = 0;
                        $payment->discount = 0;
                        $payment->surcharge = 0;
                        $payment->surcharge_reason = null;
                        $payment->service_charge_amount = 0;
                        $payment->sub_total_before_discount = 0;
                        $payment->total_incl_vat_before_discount = 0;
                    }
                    $payment->save();
                    Log::info('Edge delete: removed from items JSON', [
                        'payment_id' => $payment->id,
                        'product_key' => $productKey,
                    ]);
                    // Update Table listitem in ALL paths
                    $tblId = $tableId ?: $payment->table_id;
                    if (!empty($tblId)) {
                        Table::whereKey($tblId)->where('store_id', $storeId)->update([
                            'listitem' => $payment->items,
                        ]);
                    }
                    return $payment;
                }

                if ($deleteQuantity > (int) $detail->quantity) {
                    throw new \InvalidArgumentException('invalid_delete_quantity');
                }

                if ($deletePayment) {
                    $tableId = $tableId ?: $payment->table_id;
                    if (!empty($tableId)) {
                        $this->clearTableAfterPayment($tableId, $storeId);
                    }
                    $payment->items = json_encode([]);
                    $payment->discount = 0;
                    $payment->surcharge = 0;
                    $payment->surcharge_reason = null;
                    $payment->tax = 0;
                    $payment->total = 0;
                    $payment->final_total = 0;
                    $payment->status = -1;
                    $payment->save();
                    $payment->details()->update(['delete_note' => $deleteNote]);
                    $payment->details()->delete();
                    $payment->delete();
                } else {
                    if (!empty($productList) && is_array($productList)) {
                        // FE sends product_list after deletion, so do not subtract quantity again.
                        $calculation = $this->buildCalculatedPaymentData($productList, $storeId, $calculationInput);
                        $payment->items = $calculation['items_payload'];
                        $payment->discount = $calculation['discount'];
                        $payment->surcharge = $calculation['surcharge'];
                        $payment->surcharge_reason = $calculation['surcharge_reason'];
                        $payment->tax = $calculation['tax'];
                        $payment->total = $calculation['total'];
                        $payment->final_total = $calculation['final_total'];
                        $payment->service_charge = $calculation['service_charge'];
                        $payment->service_charge_amount = $calculation['service_charge_amount'];
                        $payment->sub_total_before_discount = $calculation['sub_total_before_discount'];
                        $payment->total_incl_vat_before_discount = $calculation['total_incl_vat_before_discount'];
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
                        $calculation = $this->buildCalculatedPaymentData($rawItems, $storeId, $calculationInput);
                        $payment->items = $calculation['items_payload'];
                        $payment->discount = $calculation['discount'];
                        $payment->surcharge = $calculation['surcharge'];
                        $payment->surcharge_reason = $calculation['surcharge_reason'];
                        $payment->tax = $calculation['tax'];
                        $payment->total = $calculation['total'];
                        $payment->final_total = $calculation['final_total'];
                        $payment->service_charge = $calculation['service_charge'];
                        $payment->service_charge_amount = $calculation['service_charge_amount'];
                        $payment->sub_total_before_discount = $calculation['sub_total_before_discount'];
                        $payment->total_incl_vat_before_discount = $calculation['total_incl_vat_before_discount'];
                    }
                    $payment->save();

                    // Update Table listitem
                    if (!empty($tableId)) {
                        Table::whereKey($tableId)->where('store_id', $storeId)->update([
                            'listitem' => $payment->items,
                        ]);
                    }

                    if ($detail) {
                        if ((int) $detail->quantity === $deleteQuantity) {
                            $detail->delete_note = $deleteNote;
                            $detail->save();
                            $detail->delete();
                        } else if ($deleteQuantity < (int) $detail->quantity) {
                            $originalQuantity = max(1, (int) $detail->quantity);

                            $remainingDetail = $detail->replicate();
                            $remainingDetail->quantity = (int) $detail->quantity - $deleteQuantity;
                            $remainingDetail->delete_note = null;
                            $this->applyDetailQuantityRatio($remainingDetail, $remainingDetail->quantity, $originalQuantity);
                            $remainingDetail->save();

                            $detail->quantity = $deleteQuantity;
                            $detail->delete_note = $deleteNote;
                            $this->applyDetailQuantityRatio($detail, $detail->quantity, $originalQuantity);
                            $detail->save();
                            $detail->delete();
                        }
                    }
                }
                return $payment;
            });

            $paymentInfo = $payment->load('details')->toArray();

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.payment_detail_deleted'),
                'data' => $paymentInfo,
                'paymentInfo' => $paymentInfo,
            ]);

        } catch (\RuntimeException $th) {
            if ($th->getMessage() === 'payment_detail_not_found') {
                return response()->json([
                    'status' => false,
                    'status_code' => 404,
                    'message' => __('api.payment_detail_not_found'),
                ], 404);
            }

            Log::error('Edge delete payment detail failed', [
                'payment_id' => $request->input('payment_id'),
                'product_key' => $request->input('product_key'),
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.system_error'),
            ], 500);
        } catch (\InvalidArgumentException $th) {
            if ($th->getMessage() === 'invalid_delete_quantity') {
                return response()->json([
                    'status' => false,
                    'status_code' => 400,
                    'message' => __('api.invalid_delete_quantity'),
                ], 400);
            }

            Log::error('Edge delete payment detail failed', [
                'payment_id' => $request->input('payment_id'),
                'product_key' => $request->input('product_key'),
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.system_error'),
            ], 500);
        } catch (\Throwable $th) {
            Log::error('Edge delete payment detail failed', [
                'payment_id' => $request->input('payment_id'),
                'product_key' => $request->input('product_key'),
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.system_error'),
            ], 500);
        }
    }

    public function getSaleToday(Request $request)
    {
        $language = $request->input('language', $request->input('isCheckLanguage', 'vi'));
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
                        $totalDiscount += (int) $discount;
                    }
                }
            }

            Log::info('Edge getSaleToday: calculated total discount', ['total_discount' => $totalDiscount]);

            // Lấy danh sách payment methods
            $methods = PaymentMethod::where('store_id', $storeId)->get();
            $methodValues = $methods->pluck('value')
                ->map(fn($value) => (int) $value)
                ->filter(fn($value) => $value > 0)
                ->unique()
                ->values()
                ->all();

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
                $valInt = (int) $val;
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
                $val = (int) $method->value;
                $payment_method_customer[] = [
                    'id' => (string) $method->id,
                    'name' => $method->name,
                    'total_amount' => $saleData["payment_method_$val"] ?? 0,
                ];
            }

            // Gộp dữ liệu
            $saleData['totalDicount'] = $totalDiscount;
            $saleData['productsSoldToday'] = (string) $productsSoldToday;
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
            $query = Payment::with('details')
                ->where('store_id', $storeId)
                ->where('status', 0);
            $type = $request->input('type');
            if ($type === 'new') {
                $query->whereNull('table_id');
            } elseif ($type === 'table') {
                $query->whereNotNull('table_id');
            }
            $payments = $query->orderBy('updated_at', 'desc')->get();
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
            $payment = Payment::with('details')->where('id', $id)->withTrashed()->first();
            if (!$payment) {
                return response()->json(['status' => false, 'message' => __('api.payment_not_found')], 404);
            }
            return response()->json(['status' => true, 'data' => $payment], 200);
        } catch (\Throwable $th) {
            Log::error('Edge getPayment failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'status_code' => 500, 'message' => __('api.ISError')], 500);
        }
    }

    public function getPaymentDetailByRequest(Request $request)
    {
        $id = $request->input('id');
        if (!$id)
            return response()->json(['status' => false, 'message' => __('api.id_required')], 400);
        $this->setRequestLocale($request);
        return $this->getPaymentDetail($id);
    }

    public function getPaymentDetail($id)
    {
        try {
            $this->setRequestLocale(request());
            $storeId = config('edge_box.store_id') ?? Store::first()?->id ?? 1;
            $payment = Payment::with(['details.product', 'user', 'customer', 'table'])->where('store_id', $storeId)->where('id', $id)->first();
            if (!$payment) {
                $payment = Payment::with(['details.product', 'user', 'customer', 'table'])->where('id', $id)->withTrashed()->first();
            }
            if (!$payment) {
                return response()->json(['status' => false, 'message' => __('api.payment_not_found')], 404);
            }
            $data = $payment->toArray();
            // Fallback: if user relationship is null (cloud/edge box ID mismatch), try admin_id
            if (empty($data['user']) && !empty($data['admin_id'])) {
                $adminUser = User::find($data['admin_id']);
                if ($adminUser) {
                    $data['user'] = $adminUser->toArray();
                }
            }
            if (empty($data['user'])) {
                $data['user'] = ['id' => 0, 'name' => '', 'phone' => '', 'email' => ''];
            }
            $store = $payment->store ?? Store::find($data['store_id'] ?? config('edge_box.store_id'));
            $tz = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');
            if (!empty($data['created_at'])) {
                $data['created_at_formatted'] = \Carbon\Carbon::parse($data['created_at'])->setTimezone($tz)->format('d-m-Y H:i:s');
            }
            if (!empty($data['updated_at'])) {
                $data['updated_at_formatted'] = \Carbon\Carbon::parse($data['updated_at'])->setTimezone($tz)->format('d-m-Y H:i:s');
            }
            $data['total_tax'] = $data['tax'] ?? 0;
            $pmVal = $data['payment_method'] ?? null;
            $data['payment_method_name'] = $this->resolvePaymentMethodName($pmVal, $data['store_id'] ?? $storeId);
            // Compute valuetotal from components to avoid ex-VAT display
            $totalDb = $data['total'] ?? 0;
            $estimatedTotal = $totalDb + ($data['tax'] ?? 0) + ($data['surcharge'] ?? 0) + ($data['service_charge_amount'] ?? 0);
            $data['valuetotal'] = $data['final_total'] ?? max($totalDb, $estimatedTotal);
            // Normalize payment.discounted_price_excluding_tax in case qty changed (split)
            if (!empty($data['details'])) {
                $hasSenior = !empty($data['is_senior_discount']);
                foreach ($data['details'] as &$detail) {
                    $q = (int) ($detail['quantity'] ?? 1);
                    $u = (float) ($detail['unit_price_excluding_tax'] ?? 0);
                    $p = (float) ($detail['price'] ?? 0);
                    if ($u > 0) {
                        $discPct = (float) ($detail['discount_percent'] ?? 0);
                        $discountAmt = $discPct > 0 ? round($u * $q * $discPct / 100) : 0;
                        $correct = ($u * $q) - $discountAmt;
                        $stored = (float) ($detail['discounted_price_excluding_tax'] ?? 0);
                        if ($stored !== $correct) {
                            $detail['discounted_price_excluding_tax'] = $correct;
                        }
                    }
                    // Normalize detail_discount proportionally
                    if ($detail['detail_discount'] > 0 && $p > 0) {
                        $discPct = (float) ($detail['discount_percent'] ?? 0);
                        $expectedDisc = round($p * $q * $discPct / 100);
                        if ($expectedDisc > 0 && $expectedDisc != (float) $detail['detail_discount']) {
                            $detail['detail_discount'] = $expectedDisc;
                        }
                    }
                    // Same for detail_discount_excluding_tax
                    if ($detail['detail_discount_excluding_tax'] > 0 && $p > 0) {
                        $discPct = (float) ($detail['discount_percent'] ?? 0);
                        $expectedDisc = round($p * $q * $discPct / 100);
                        if ($expectedDisc > 0 && $expectedDisc != (float) $detail['detail_discount_excluding_tax']) {
                            $detail['detail_discount_excluding_tax'] = $expectedDisc;
                        }
                    }
                    if ($hasSenior) {
                        $detail['tax_amount'] = 0;
                    }
                }
                // Recompute subtotal from details price*qty for accuracy
                $realSubtotal = array_sum(array_map(fn($d) => (float) ($d['price'] ?? 0) * (int) ($d['quantity'] ?? 0), $data['details']));
                if ($realSubtotal > 0) {
                    $data['sub_total_before_discount'] = $realSubtotal;
                }
            }
            $data['unit_price_excluding_tax'] = 0;
            $data['detail_discount_excluding_tax'] = 0;
            $data['discounted_price_excluding_tax'] = 0;
            if (!empty($data['details'])) {
                foreach ($data['details'] as &$detail) {
                    $detail['products'] = isset($detail['product']) ? $detail['product'] : [];
                    unset($detail['product']);
                    $detail['total_price'] = $detail['total'] ?? 0;
                }
                $data['payment_details'] = $data['details'];
            } else {
                $data['payment_details'] = [];
            }
            unset($data['details']);
            return response()->json(['status' => true, 'data_payment' => [$data]], 200);
        } catch (\Throwable $th) {
            Log::error('Edge getPaymentDetail failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'status_code' => 500, 'message' => __('api.ISError')], 500);
        }
    }

    public function checkIsPrinted(Request $request)
    {
        $paymentId = $request->input('payment_id');
        if (!$paymentId) {
            return response()->json(['status' => false, 'status_code' => 400, 'message' => __('api.payment_id_required')], 400);
        }

        $payment = Payment::find($paymentId);
        if (!$payment) {
            return response()->json(['status' => false, 'status_code' => 404, 'data' => ['is_printed' => false]], 404);
        }

        $payment->is_printed = true;
        $payment->save();

        return response()->json([
            'status' => true,
            'status_code' => 200,
            'data' => [
                'is_printed' => true,
            ],
        ]);
    }

    public function getPaymentByRequest(Request $request)
    {
        $id = $request->input('id', $request->input('payment_id'));
        if (!$id) {
            return response()->json(['status' => false, 'status_code' => 400, 'message' => __('api.payment_id_required')], 400);
        }
        return $this->getPayment($id);
    }

    public function getPaymentByTable(Request $request)
    {
        try {
            $storeId = config('edge_box.store_id') ?? Store::first()?->id ?? 1;
            $tableId = $request->input('table_id', $request->input('id'));
            if (!$tableId) {
                return response()->json(['status' => false, 'status_code' => 400, 'message' => __('api.table_id_required')], 400);
            }
            $table = \App\Models\Table::with('payment.details')->where('store_id', $storeId)->where('id', $tableId)->first();
            if (!$table || !$table->payment) {
                return response()->json(['status' => false, 'status_code' => 404, 'message' => __('api.payment_not_found')], 404);
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
                ->orderBy('updated_at', 'desc')
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
            $this->setRequestLocale($request);
            $storeId = config('edge_box.store_id') ?? Store::first()?->id ?? 1;
            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 15);
            $query = $request->input('query', []);

            $paymentsQuery = Payment::with(['store', 'user', 'customer', 'details'])
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
            $payments = $paymentsQuery->orderBy('updated_at', 'desc')
                ->skip(($page - 1) * $pageSize)
                ->take($pageSize)
                ->get();

            $paymentMethodNames = [
                1 => 'Tiền mặt',
                2 => 'Chuyển khoản',
                3 => 'Thanh toán bằng mã QR',
                4 => 'Thẻ tín dụng',
                5 => 'Ví điện tử',
                6 => 'Khác',
                'cash' => 'Tiền mặt',
                'transfer' => 'Chuyển khoản',
                'ewallet' => 'Ví điện tử',
                'credit_card' => 'Thẻ tín dụng',
            ];
            $payments = $payments->map(function ($p) use ($paymentMethodNames) {
                $data = $p->toArray();
                // User fallback from admin_id
                if (empty($data['user']) && !empty($data['admin_id'])) {
                    $adminUser = \App\Models\User::find($data['admin_id']);
                    if ($adminUser) {
                        $data['user'] = $adminUser->toArray();
                    }
                }
                if (empty($data['user'])) {
                    $data['user'] = ['id' => 0, 'name' => ''];
                }
                $data['reasonSurcharge'] = $data['surcharge_reason'] ?? '';
                $data['customer'] = $data['customer'] ?? null;
                $data['payment_details'] = $data['details'] ?? [];
                $data['sub_total_before_discount'] = $data['sub_total_before_discount'] ?? 0;
                $data['total_incl_vat_before_discount'] = $data['total_incl_vat_before_discount'] ?? 0;
                $data['total_tax'] = $data['tax'] ?? 0;
                $data['service_charge_amount'] = $data['service_charge_amount'] ?? 0;
                // Compute valuetotal from components to avoid ex-VAT display
                $totalFromDb = $data['total'] ?? 0;
                $estimatedTotal = $totalFromDb + ($data['tax'] ?? 0) + ($data['surcharge'] ?? 0) + ($data['service_charge_amount'] ?? 0);
                $data['valuetotal'] = $data['final_total'] ?? max($totalFromDb, $estimatedTotal);
                if (is_string($data['payment_method'])) {
                    $map = array_flip($paymentMethodNames);
                    $data['payment_method'] = $map[$data['payment_method']] ?? (is_numeric($data['payment_method']) ? (int) $data['payment_method'] : 0);
                }
                $data['payment_method_name'] = $this->resolvePaymentMethodName($data['payment_method'] ?? null, $data['store_id'] ?? $storeId);
                $store = $p->store;
                $tz = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');
                $data['created_at'] = \Carbon\Carbon::parse($data['created_at'])->setTimezone($tz)->format('d-m-Y H:i:s');
                $data['updated_at'] = \Carbon\Carbon::parse($data['updated_at'])->setTimezone($tz)->format('d-m-Y H:i:s');
                if (!empty($data['details'])) {
                    foreach ($data['details'] as &$detail) {
                        if (empty($detail['products']) && !empty($detail['product_id'])) {
                            $product = \App\Models\Product::find($detail['product_id']);
                            $detail['products'] = $product ? $product->toArray() : [];
                        }
                    }
                }
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
                'revenue' => (float) $totalRevenue,
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
                'message' => __('api.date_required'),
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
                'revenue' => (float) $totalRevenue,
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
                'message' => __('api.date_required'),
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
                'revenue' => (float) $totalRevenue,
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
