<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Table;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SplitMergeInvoiceController extends Controller
{
    /**
     * status = 0: Pending/Unpaid payment (EStatusPayment::PENDING)
     * status = 1: Active/Paid payment (EStatusPayment::ACTIVE)
     */

    public function getListInvoice(Request $request)
    {
        try {
            $storeId = (int) $request->input('store_id', config('app.store_id'));

            $query1 = Payment::where('payments.store_id', $storeId)
                ->where('payments.status', 0) // Unpaid
                ->join('table', 'payments.id', '=', 'table.payment_id')
                ->select(
                    'payments.id',
                    'payments.status',
                    'payments.payment_code',
                    'payments.store_id',
                    'payments.table_id',
                    'payments.customer_id',
                    'payments.total as valuetotal'
                );

            $query2 = Payment::where('store_id', $storeId)
                ->where('status', 0) // Unpaid
                ->whereNull('table_id')
                ->select(
                    'id',
                    'status',
                    'payment_code',
                    'store_id',
                    'table_id',
                    'customer_id',
                    'total as valuetotal'
                );

            $payments = $query1->union($query2)->get();
            $payments = $payments->map(function ($item) {
                $item->take_away = $item->table_id === null;
                $item->payment_name = $item->payment_method ?? 'Khác';
                return $item;
            });

            $payments->load([
                'table' => function ($query) {
                    $query->select('id', 'name as tablename', 'store_id', 'payment_id');
                },
                'customer' => function ($query) {
                    $query->select('id', 'name', 'store_id');
                }
            ]);

            app()->setLocale($request->input('isCheckLanguage', 'vi'));

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => __('api.invoice_list_success'),
                'data' => $payments,
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge getListInvoice failed: ' . $th->getMessage());
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => __('api.ISError'),
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function splitInvoice(Request $request)
    {
        $filters = $request->all();
        $storeId = (int) $request->input('store_id', config('app.store_id'));
        $filters['store_id'] = $storeId;
        $filters['_split_trace_id'] = sprintf(
            'split-%s-%s',
            now()->format('YmdHisv'),
            bin2hex(random_bytes(3))
        );
        Log::info('EDGE_SPLIT_TRACE request.received', [
            'trace_id' => $filters['_split_trace_id'],
            'store_id' => $storeId,
            'original_invoice_id' => $filters['original_invoice_id'] ?? null,
            'create_payment' => !empty($filters['create_payment']),
            'target_table_id' => $filters['target_table_id'] ?? null,
            'target_invoice_id' => $filters['target_invoice_id'] ?? null,
            'source_sha1' => sha1_file(__FILE__),
            'source_mtime' => date('c', filemtime(__FILE__)),
            'php_sapi' => PHP_SAPI,
            'process_id' => getmypid(),
        ]);
        $language = $request->input('language', $request->input('isCheckLanguage', 'vi'));
        app()->setLocale($language);

        DB::beginTransaction();
        try {
            $originalInvoice = Payment::with('details')
                ->where('store_id', $storeId)
                ->find($filters['original_invoice_id']);
            if (!$originalInvoice) {
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 404, 'message' => __('api.invoice_not_found')], 404);
            }

            $splitMethod = '';
            if (isset($filters['target_table_id'])) {
                $splitMethod = 'splitInvoiceTargetTable';
            } elseif (isset($filters['target_invoice_id'])) {
                $splitMethod = 'splitInvoiceTargetPayment';
            } elseif (!empty($filters['create_payment'])) {
                $splitMethod = 'createInvoiceFromOriginal';
            }

            if (!$splitMethod) {
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 400, 'message' => __('api.split_method_unknown')], 400);
            }

            $res = $this->$splitMethod($filters, $originalInvoice);

            if (!$res['status']) {
                DB::rollBack();
                return response()->json($res, $res['status_code'] ?? 400);
            }

            DB::commit();
            return response()->json($res, 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Edge splitInvoice failed: ' . $th->getMessage());
            return response()->json(['status' => false, 'status_code' => 500, 'message' => __('api.ISError') . ': ' . $th->getMessage()], 500);
        }
    }

    private function splitInvoiceTargetTable($filters, $originalInvoice)
    {
        $itemOriginalInvoice = $this->getPaymentItems($originalInvoice);
        $handleData = $this->handleDataSplitInvoice($itemOriginalInvoice['item'], $filters['split_merge_item'], $filters['store_id']);
        if (!$handleData['status']) {
            return $handleData;
        }

        $paramUpdateOriginalInvoice = $this->handleUpdateOriginalInvoice($itemOriginalInvoice, $originalInvoice, $filters);
        $this->updatePaymentLocal($paramUpdateOriginalInvoice, true);
        $savedParent = Payment::find($originalInvoice->id);
        if ($savedParent) {
            $savedParentData = $savedParent->toArray();
            $savedParentData['_split_trace_id'] = $filters['_split_trace_id'] ?? null;
            Log::info(
                'EDGE_SPLIT_TRACE parent.saved',
                $this->paymentCalculationLogContext($savedParentData, Store::find($originalInvoice->store_id))
            );
        }

        if (!empty($originalInvoice->table_id)) {
            $this->updateTableListitemAfterSplit($originalInvoice->table_id, $itemOriginalInvoice);
        }

        $filters['_remain_vt'] = $paramUpdateOriginalInvoice['valuetotal'] ?? 0;
        $filters['_remain_disc'] = $paramUpdateOriginalInvoice['discount'] ?? 0;
        $filters['_remain_surcharge'] = $paramUpdateOriginalInvoice['surcharge'] ?? 0;
        $filters['_remain_sc'] = $paramUpdateOriginalInvoice['service_charge_amount'] ?? 0;
        $filters['_remain_senior'] = $paramUpdateOriginalInvoice['senior_discount_amount'] ?? 0;
        $filters['_remain_tax'] = $paramUpdateOriginalInvoice['total_tax'] ?? 0;
        $paramCreateNewInvoice = $this->handleUpdateNewInvoice($filters, $originalInvoice);
        $create = $this->createPaymentLocal($paramCreateNewInvoice);
        if (!$create['status']) {
            return $create;
        }

        $checkTable = Table::where('store_id', $filters['store_id'])
            ->find($filters['target_table_id']);
        if (!$checkTable) {
            return ['status' => false, 'status_code' => 404, 'message' => __('api.table_not_found')];
        }

        $checkTable->update([
            'status' => 2,
            'user_id' => $originalInvoice->user_id,
            'payment_id' => $create['payment']['id'],
            'lock_time' => now()->addMinutes(5)->format('Y-m-d H:i:s'),
            'listitem' => $create['payment']['items'],
        ]);

        return [
            'status' => true,
            'status_code' => 200,
            'message' => __('api.split_success'),
            'data' => [
                $create['payment'],
                $this->refreshPaymentWithDetails($originalInvoice->id),
            ]
        ];
    }

    private function splitInvoiceTargetPayment($filters, $originalInvoice)
    {
        $checkTargetInvoice = Payment::with('details')
            ->where('store_id', $filters['store_id'])
            ->find($filters['target_invoice_id']);
        if (!$checkTargetInvoice) {
            return ['status' => false, 'status_code' => 404, 'message' => __('api.invoice_not_found')];
        }

        $itemOriginalInvoice = $this->getPaymentItems($originalInvoice);
        $handleData = $this->handleDataSplitInvoice($itemOriginalInvoice['item'], $filters['split_merge_item'], $filters['store_id']);
        if (!$handleData['status']) {
            return $handleData;
        }

        $paramUpdateOriginalInvoice = $this->handleUpdateOriginalInvoice($itemOriginalInvoice, $originalInvoice, $filters);
        $this->updatePaymentLocal($paramUpdateOriginalInvoice, true);
        $savedParent = Payment::find($originalInvoice->id);
        if ($savedParent) {
            $savedParentData = $savedParent->toArray();
            $savedParentData['_split_trace_id'] = $filters['_split_trace_id'] ?? null;
            Log::info(
                'EDGE_SPLIT_TRACE parent.saved',
                $this->paymentCalculationLogContext($savedParentData, Store::find($originalInvoice->store_id))
            );
        }

        if (!empty($originalInvoice->table_id)) {
            $this->updateTableListitemAfterSplit($originalInvoice->table_id, $itemOriginalInvoice);
        }

        $paramUpdateTargetInvoice = $this->handleData4TargetInvoice($filters, $checkTargetInvoice);
        $updateTargetInvoice = $this->updatePaymentLocal($paramUpdateTargetInvoice);

        if (!empty($updateTargetInvoice->table_id)) {
            $this->updateTableAfterSplitMerge($updateTargetInvoice->table_id, $updateTargetInvoice);
        }

        return [
            'status' => true,
            'status_code' => 200,
            'message' => __('api.split_success'),
            'data' => [
                $this->refreshPaymentWithDetails($originalInvoice->id),
                $this->refreshPaymentWithDetails($checkTargetInvoice->id),
            ]
        ];
    }

    private function allocateDiscountTaxExcluded(array $items, float $discountValue, string $type_discount = 'amount'): array
    {
        $totalBase = 0;
        foreach ($items as $item) {
            $totalBase += $item['price'] * $item['quantity'];
        }
        if ($totalBase <= 0) {
            return ['items' => [], 'summary' => []];
        }
        $allocatedSum = 0;
        $lastKey = array_key_last($items);
        foreach ($items as $key => &$item) {
            $subTotal = $item['price'] * $item['quantity'];
            $item['sub_total_excl_vat'] = round($subTotal);
            if ($type_discount === 'percent') {
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
            $item['tax_amount'] = round($item['net_excl_vat'] * ($item['vat'] / 100));
            $item['total_with_vat_after_discount'] = $item['net_excl_vat'] + $item['tax_amount'];
            $item['detail_discount'] = $item['discount_allocated_excl_vat'];
            $item['TotalPrice'] = round($item['sub_total_excl_vat'] * (1 + $item['vat'] / 100));
            $item['detail_discount_excluding_tax'] = $item['discount_allocated_excl_vat'];
            $item['unit_price_excluding_tax'] = $item['price'];
            $item['discounted_price_excluding_tax'] = $item['net_excl_vat'];
        }
        unset($item);
        $summary = [
            'subtotal_before' => array_sum(array_column($items, 'sub_total_excl_vat')),
            'discount_total' => ($type_discount === 'percent') ? round($totalBase * $discountValue / 100) : $discountValue,
            'subtotal_after' => array_sum(array_column($items, 'net_excl_vat')),
            'total_vat' => array_sum(array_column($items, 'tax_amount')),
            'total_with_vat' => array_sum(array_column($items, 'total_with_vat_after_discount')),
            'total_incl_vat_before_discount' => array_sum(array_column($items, 'TotalPrice')),
        ];
        return ['items' => $items, 'summary' => $summary];
    }

    private function allocateDiscountTaxIncluded(array $items, float $discountValue, string $type_discount = 'amount'): array
    {
        $totalWithVat = 0;
        foreach ($items as $item) {
            $totalWithVat += $item['price'] * $item['quantity'];
        }
        if ($totalWithVat <= 0) {
            return ['items' => [], 'summary' => []];
        }
        $allocatedSum = 0;
        $lastKey = array_key_last($items);
        foreach ($items as $key => &$item) {
            $subTotalInclVat = $item['price'] * $item['quantity'];
            $item['sub_total_incl_vat'] = round($subTotalInclVat);
            if ($type_discount === 'percent') {
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
            $item['unit_price_excluding_tax'] = round($item['price'] / (1 + $item['vat'] / 100));
            $item['discount_allocated_excl_vat'] = round($item['discount_allocated_incl_vat'] / (1 + $item['vat'] / 100));
            $item['total_with_vat_after_discount'] = $item['sub_total_incl_vat'] - $item['discount_allocated_incl_vat'];
            $item['net_excl_vat'] = round($item['total_with_vat_after_discount'] / (1 + $item['vat'] / 100));
            $item['tax_amount'] = $item['total_with_vat_after_discount'] - $item['net_excl_vat'];
            $item['detail_discount'] = $item['discount_allocated_incl_vat'];
            $item['TotalPrice'] = $item['sub_total_incl_vat'];
            $item['detail_discount_excluding_tax'] = $item['discount_allocated_excl_vat'];
            $item['discounted_price_excluding_tax'] = $item['net_excl_vat'];
        }
        unset($item);
        $summary = [
            'subtotal_before' => array_sum(array_map(fn($i) => $i['sub_total_incl_vat'] / (1 + $i['vat'] / 100), $items)),
            'discount_total' => ($type_discount === 'percent') ? round($totalWithVat * $discountValue / 100) : $discountValue,
            'subtotal_after' => array_sum(array_column($items, 'net_excl_vat')),
            'total_vat' => array_sum(array_column($items, 'tax_amount')),
            'total_with_vat' => array_sum(array_column($items, 'total_with_vat_after_discount')),
            'total_incl_vat_before_discount' => array_sum(array_column($items, 'TotalPrice')),
        ];
        return ['items' => $items, 'summary' => $summary];
    }

    private function createInvoiceFromOriginal($filters, $originalInvoice)
    {
        Log::debug("createInvoiceFromOriginal DBG - Starting split. Filters: " . json_encode($filters));
        Log::debug("createInvoiceFromOriginal DBG - Original payment ID: " . $originalInvoice->id . ", details: " . json_encode($originalInvoice->toArray()));

        $itemOriginalInvoice = $this->getPaymentItems($originalInvoice);
        // Load printed_quantity from payment_details (items JSON doesn't store it)
        $paymentDetails = $originalInvoice->details()->get();
        if ($paymentDetails->isNotEmpty()) {
            foreach ($paymentDetails as $detail) {
                $productKey = $detail->product_key;
                if ($productKey && isset($itemOriginalInvoice['item'][$productKey])) {
                    $itemOriginalInvoice['item'][$productKey]['printed_quantity'] = (int) $detail->printed_quantity;
                }
            }
        }
        $handleData = $this->handleDataSplitInvoice($itemOriginalInvoice['item'], $filters['split_merge_item'], $filters['store_id']);
        if (!$handleData['status']) {
            return $handleData;
        }

        $paramUpdateOriginalInvoice = $this->handleUpdateOriginalInvoice($itemOriginalInvoice, $originalInvoice, $filters);
        Log::debug("createInvoiceFromOriginal DBG - Recalculated Parent (paramUpdateOriginalInvoice): " . json_encode($paramUpdateOriginalInvoice));
        $this->updatePaymentLocal($paramUpdateOriginalInvoice, true);
        $savedParent = Payment::find($originalInvoice->id);
        if ($savedParent) {
            $savedParentData = $savedParent->toArray();
            $savedParentData['_split_trace_id'] = $filters['_split_trace_id'] ?? null;
            Log::info(
                'EDGE_SPLIT_TRACE parent.saved',
                $this->paymentCalculationLogContext($savedParentData, Store::find($originalInvoice->store_id))
            );
        }

        if (!empty($originalInvoice->table_id)) {
            $this->updateTableListitemAfterSplit($originalInvoice->table_id, $itemOriginalInvoice);
        }

        // Compute split totals independently from split items
        $store = Store::find($filters['store_id']);
        $isTaxInc = $store ? ($store->is_tax_included ?? false) : false;

        $itemsDecoded = ['item' => $filters['split_merge_item']];
        $billItem = $isTaxInc
            ? $this->allocateDiscountTaxIncluded($itemsDecoded['item'], 0, 'amount')
            : $this->allocateDiscountTaxExcluded($itemsDecoded['item'], 0, 'amount');

        $splitSubtotalBefore = $billItem['summary']['subtotal_before'];
        $splitTotalInclVat = $billItem['summary']['total_incl_vat_before_discount'];

        $isSenior = isset($filters['is_senior_discount'])
            ? !empty($filters['is_senior_discount'])
            : !empty($originalInvoice->is_senior_discount);

        $splitExVatBase = ($isTaxInc && !$isSenior) ? $splitTotalInclVat : $splitSubtotalBefore;

        $typeDiscount = $filters['type_discount'] ?? ($originalInvoice->type_discount ?? 'amount');
        $discountPct = (float) ($filters['discount_percent'] ?? ($originalInvoice->discount_percent ?? 0));

        $seniorRate = (float) config('params.senior_discount.rate', 20);
        $seniorAmount = $isSenior ? round($splitSubtotalBefore * $seniorRate / 100) : 0;
        $isSeniorActive = $isSenior && $seniorAmount > 0;
        $afterSenior = $splitExVatBase - $seniorAmount;

        $discountVal = $typeDiscount === 'percent' ? $discountPct : 0;
        $discountAmount = 0;
        if (isset($filters['discount']) && $typeDiscount === 'amount') {
            $discountAmount = (float) $filters['discount'];
        } elseif ($isSeniorActive && $typeDiscount === 'percent') {
            $discountAmount = round($afterSenior * $discountPct / 100);
        } elseif ($typeDiscount === 'percent') {
            $discountAmount = round($splitExVatBase * $discountPct / 100);
        } else {
            $origItems = json_decode($originalInvoice->items, true)['item'] ?? [];
            $origTotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $origItems));
            $splitTotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $filters['split_merge_item']));
            if ($origTotal > 0) {
                $discountAmount = round(($originalInvoice->discount ?? 0) * $splitTotal / $origTotal);
            }
        }

        // Allocate final discount to items for correct JSON payload
        $billItem = $isTaxInc
            ? $this->allocateDiscountTaxIncluded($itemsDecoded['item'], $typeDiscount === 'percent' ? $discountPct : $discountAmount, $typeDiscount)
            : $this->allocateDiscountTaxExcluded($itemsDecoded['item'], $typeDiscount === 'percent' ? $discountPct : $discountAmount, $typeDiscount);

        $totalAfterDiscount = $afterSenior - $discountAmount;
        $total_tax = $isSeniorActive ? 0 : $billItem['summary']['total_vat'];

        $serviceChargePercent = (float) ($filters['service_charge'] ?? ($originalInvoice->service_charge ?? 0));
        $serviceChargeAmount = round(max(0, $totalAfterDiscount) * $serviceChargePercent / 100);

        $surchargePercent = (float) ($filters['surcharge_percent'] ?? ($originalInvoice->surcharge_percent ?? 0));
        if ($surchargePercent > 0) {
            $surchargeAmount = round(max(0, $totalAfterDiscount) * $surchargePercent / 100);
        } elseif (isset($filters['surcharge'])) {
            $surchargeAmount = (float) $filters['surcharge'];
        } else {
            $surchargeAmount = max(0, (float) ($originalInvoice->surcharge ?? 0) - (float) ($paramUpdateOriginalInvoice['surcharge'] ?? 0));
        }

        $valuetotal = max(0, $totalAfterDiscount + ($isTaxInc && !$isSeniorActive ? 0 : $total_tax) + $serviceChargeAmount + $surchargeAmount);

        $dataItem = [];
        // Match Cloud: createPaymentLocal/handlePaymentData owns the final item allocation.
        $dataItem['item'] = $filters['split_merge_item'];
        $dataItem['discountPayment'] = $discountAmount;
        $dataItem['reasonSurcharge'] = $filters['reasonSurcharge'] ?? null;
        $dataItem['surcharge'] = $surchargeAmount;
        $dataItem['total_tax'] = $total_tax;
        $items = json_encode($dataItem);

        $paymentCode = 'EDGE-' . date('YmdHis') . '-' . random_int(1000, 9999);

        $storeId = (int) $filters['store_id'];
        $resolvedUserId = $this->resolveUserForStore($storeId, $filters['user_id'] ?? null, $originalInvoice->user_id);
        $resolvedAdminId = $this->resolveUserForStore($storeId, $filters['admin_id'] ?? null, $originalInvoice->admin_id ?: $resolvedUserId);

        $paramCreatePayment = [
            "reason" => $filters['reason'] ?? null,
            "customer_id" => $filters['customer_id'] ?? null,
            "items" => $items,
            "discount" => $discountAmount,
            "type_discount" => $typeDiscount,
            "discount_percent" => $typeDiscount === 'percent' ? $discountPct : null,
            "surcharge" => $surchargeAmount,
            "surcharge_percent" => $surchargePercent,
            "payment_method" => $filters['payment_method'] ?? 'cash',
            "status" => 1, // Paid
            "surcharge_reason" => $filters['surcharge_reason'] ?? null,
            "amount_received" => $filters['amount_received'] ?? null,
            "admin_id" => $resolvedAdminId,
            "user_id" => $resolvedUserId,
            "payment_code" => $paymentCode,
            "store_id" => $storeId,
            "table_id" => $originalInvoice->table_id,
            "parent_id" => $originalInvoice->id,
            "valuetotal" => $valuetotal,
            "total_tax" => $isSeniorActive ? 0 : $total_tax,
            "is_senior_discount" => $isSenior,
            "senior_discount_amount" => $seniorAmount,
            "service_charge" => $serviceChargePercent,
            "service_charge_amount" => $serviceChargeAmount,
            "sub_total_before_discount" => max(0, (float) ($originalInvoice->sub_total_before_discount ?? 0) - (float) ($paramUpdateOriginalInvoice['sub_total_before_discount'] ?? 0)),
            "total_incl_vat_before_discount" => max(0, (float) ($originalInvoice->total_incl_vat_before_discount ?? 0) - (float) ($paramUpdateOriginalInvoice['total_incl_vat_before_discount'] ?? 0)),
            "_split_trace_id" => $filters['_split_trace_id'] ?? null,
        ];

        Log::info(
            'EDGE_SPLIT_TRACE child.prepared',
            $this->paymentCalculationLogContext($paramCreatePayment, $store)
        );
        Log::debug("createInvoiceFromOriginal DBG - Recalculated Child (paramCreatePayment): " . json_encode($paramCreatePayment));
        $createPayment = $this->createPaymentLocal($paramCreatePayment);
        if (!$createPayment['status']) {
            return $createPayment;
        }

        return [
            'status' => true,
            'status_code' => 200,
            'message' => __('api.split_success'),
            'data' => [
                'parent_bill' => $this->refreshPaymentWithDetails($originalInvoice->id),
                'split_bill' => $createPayment['payment']
            ]
        ];
    }

    public function mergeInvoice(Request $request)
    {
        $filters = $request->all();
        $storeId = (int) $request->input('store_id', config('app.store_id'));
        $filters['store_id'] = $storeId;

        DB::beginTransaction();
        try {
            app()->setLocale($request->input('isCheckLanguage', 'vi'));
            $originalInvoice = Payment::where('store_id', $storeId)
                ->find($filters['original_invoice_id']);
            if (!$originalInvoice) {
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 404, 'message' => __('api.invoice_not_found')], 404);
            }
            if ($originalInvoice->status !== 0) { // Pending/Unpaid
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 400, 'message' => __('api.invoice_inactive')], 400);
            }

            $targetInvoice = Payment::where('store_id', $storeId)
                ->find($filters['target_invoice_id']);
            if (!$targetInvoice) {
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 404, 'message' => __('api.invoice_not_found')], 404);
            }
            if ($targetInvoice->status !== 0) { // Pending/Unpaid
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 400, 'message' => __('api.invoice_inactive')], 400);
            }

            $paramTargetInvoice = $this->handleData4MergeInvoice($originalInvoice, $targetInvoice);
            $this->updatePaymentLocal($paramTargetInvoice);

            if ($targetInvoice->table_id !== null) {
                Table::where('id', $targetInvoice->table_id)
                    ->where('payment_id', $targetInvoice->id)
                    ->update(['listitem' => $paramTargetInvoice['items']]);
            }

            if ($originalInvoice->table_id !== null) {
                Table::where('id', $originalInvoice->table_id)->update([
                    'status' => 1,
                    'number_of_people' => 0,
                    'payment_id' => null,
                    'listitem' => null
                ]);
            }

            $originalInvoice->details()->delete();
            $originalInvoice->delete();

            DB::commit();
            return response()->json(['status' => true, 'status_code' => 200, 'message' => __('api.merge_success')]);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Edge mergeInvoice failed: ' . $th->getMessage());
            return response()->json(['status' => false, 'status_code' => 500, 'message' => __('api.ISError') . ': ' . $th->getMessage()], 500);
        }
    }

    private function handleDataSplitInvoice(&$original_invoice, &$split_merge_item, $store_id)
    {
        try {
            Log::debug('handleDataSplitInvoice DBG - Original keys: ' . json_encode(array_keys($original_invoice ?? [])));
            Log::debug('handleDataSplitInvoice DBG - Split keys: ' . json_encode(array_keys($split_merge_item ?? [])));
            $store = Store::find($store_id);
            $is_tax_included = $store->is_tax_included ?? 0;
            foreach ($split_merge_item as $key => $value) {
                if (!isset($original_invoice[$key])) {
                    $origKeys = array_keys($original_invoice ?? []);
                    $splitKeys = array_keys($split_merge_item ?? []);
                    return [
                        'status' => false,
                        'status_code' => 404,
                        'message' => "Không tìm thấy sản phẩm '" . $split_merge_item[$key]['title'] . "' trong hóa đơn. Key tìm kiếm: '$key'. Các keys hiện có trong hóa đơn gốc: " . json_encode($origKeys) . ", các keys trong split_merge_item: " . json_encode($splitKeys)
                    ];
                }
                $quantityRemain = $original_invoice[$key]['quantity'] - $split_merge_item[$key]['quantity'];
                $splitQuantity = $split_merge_item[$key]['quantity'] ?? 0;
                $originalPrinted = $original_invoice[$key]['printed_quantity'] ?? 0;

                // Proportion printed_quantity (same as cloud)
                $split_merge_item[$key]['printed_quantity'] = max(0, $originalPrinted - max(0, $quantityRemain));
                $original_invoice[$key]['printed_quantity'] = $originalPrinted - $split_merge_item[$key]['printed_quantity'];

                if ($quantityRemain <= 0) {
                    unset($original_invoice[$key]);
                } else {
                    $original_invoice[$key]['quantity'] = $quantityRemain;
                    $TotalPrice = $is_tax_included ? intval($original_invoice[$key]['quantity']) * floatval($original_invoice[$key]['price']) : $this->calculateTotalAfterTax($original_invoice[$key])['total'];
                    $original_invoice[$key]['TotalPrice'] = $TotalPrice;
                }
                $TotalPrice = $is_tax_included ? intval($splitQuantity) * floatval($split_merge_item[$key]['price']) : $this->calculateTotalAfterTax($split_merge_item[$key])['total'];
                $split_merge_item[$key]['TotalPrice'] = $TotalPrice;
            }
            return ['status' => true];
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    private function calculateTotalAfterTax(array $item, bool $isTaxIncluded = false): array
    {
        $quantity = intval($item['quantity']);
        $price = floatval($item['price']);
        $vatPercent = floatval($item['vat'] ?? 0);
        $subtotal = $quantity * $price;
        if ($isTaxIncluded) {
            $vatAmount = $vatPercent > 0 ? round($subtotal * $vatPercent / (100 + $vatPercent)) : 0;
            $totalAfterTax = $subtotal;
        } else {
            $vatAmount = round($subtotal * ($vatPercent / 100));
            $totalAfterTax = $subtotal + $vatAmount;
        }
        return [
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => $subtotal,
            'vatPercent' => $vatPercent . '%',
            'vatAmount' => $vatAmount,
            'total' => $totalAfterTax,
        ];
    }

    private function computeTotalsFromItems(array $items, bool $isTaxInc): array
    {
        $total_value = 0;
        $total_tax = 0;
        foreach ($items as $value) {
            if ($isTaxInc) {
                $itemTotal = (float) ($value['TotalPrice'] ?? ($value['price'] * $value['quantity']));
                $total_value += $itemTotal;
                $vatPct = (float) ($value['vat'] ?? 0);
                $total_tax += $vatPct > 0 ? round($itemTotal * $vatPct / (100 + $vatPct)) : 0;
            } else {
                $calc = $this->calculateTotalAfterTax($value, false);
                $total_tax += $calc['vatAmount'];
                $total_value += $calc['total'];
            }
        }
        return ['total_value' => $total_value, 'total_tax' => $total_tax];
    }

    private function handleUpdateOriginalInvoice($itemOriginalInvoice, $original_invoice, $filters = [])
    {
        Log::debug("handleUpdateOriginalInvoice DBG - Filters received: " . json_encode($filters));
        $storeOrig = Store::find($original_invoice->store_id);
        $isTaxInc = $storeOrig->is_tax_included ?? 0;

        $typeDiscount = $original_invoice->type_discount ?? 'amount';
        $discountValue = $typeDiscount === 'percent'
            ? (float) ($original_invoice->discount_percent ?? 0)
            : ((float) ($original_invoice->discount ?? 0) * array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $itemOriginalInvoice['item'])) / max(1, $original_invoice->sub_total_before_discount ?? array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], json_decode($original_invoice->items, true)['item'] ?? []))));

        $billItem = $isTaxInc
            ? $this->allocateDiscountTaxIncluded($itemOriginalInvoice['item'], $discountValue, $typeDiscount)
            : $this->allocateDiscountTaxExcluded($itemOriginalInvoice['item'], $discountValue, $typeDiscount);

        $itemOriginalInvoice['item'] = $billItem['items'];
        $total_tax = $billItem['summary']['total_vat'];
        $totalWithVat = $billItem['summary']['total_with_vat'];
        $itemOriginalInvoice['total_tax'] = $total_tax;

        $surchargePercent = $original_invoice->surcharge_percent ?? null;
        $surchargeAmount = (float) ($original_invoice->surcharge ?? 0);

        if (empty($surchargePercent) && $surchargeAmount > 0) {
            $origItems = json_decode($original_invoice->items, true)['item'] ?? [];
            $origTotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $origItems));
            $remainTotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $itemOriginalInvoice['item']));
            if ($origTotal > 0) {
                $surchargeAmount = round($surchargeAmount * $remainTotal / $origTotal);
            } else {
                $surchargeAmount = 0;
            }
        }

        $serviceCharge = (int) ($original_invoice->service_charge ?? $storeOrig->service_charge ?? 0);
        $serviceChargeAmount = (float) ($original_invoice->service_charge_amount ?? 0);
        $isSeniorActive = !empty($original_invoice->is_senior_discount);
        $seniorAmount = (float) ($original_invoice->senior_discount_amount ?? 0);

        if (($storeOrig->time_zone ?? null) === 'Asia/Manila') {
            $baseForSurcharge = $isTaxInc ? $totalWithVat : ($billItem['summary']['subtotal_after'] ?? $totalWithVat);

            if ($surchargePercent !== null) {
                $surchargeAmount = $baseForSurcharge * $surchargePercent / 100;
            }
            $serviceChargeAmount = round($baseForSurcharge * $serviceCharge / 100);

            if ($isSeniorActive) {
                $scBaseTotal = round($billItem['summary']['subtotal_before']);
                $seniorAmount = round($scBaseTotal * 20 / 100);
                $afterSenior = $scBaseTotal - $seniorAmount;

                if ($typeDiscount === 'percent') {
                    $billItem['summary']['discount_total'] = round($afterSenior * $discountValue / 100);
                }

                $totalAfterDiscount = $afterSenior - $billItem['summary']['discount_total'];
                $total_tax = 0;
                $serviceChargeAmount = round(max(0, $totalAfterDiscount) * $serviceCharge / 100);

                if ($surchargePercent !== null) {
                    $surchargeAmount = round(max(0, $totalAfterDiscount) * $surchargePercent / 100);
                }

                $totalWithVat = max(0, $totalAfterDiscount) + $serviceChargeAmount + $surchargeAmount;
            } else {
                $totalWithVat = $totalWithVat + $serviceChargeAmount + $surchargeAmount;
            }
        } else {
            $totalWithVat = $totalWithVat + $serviceChargeAmount + $surchargeAmount;
        }

        $discountAmount = $billItem['summary']['discount_total'];
        $valuetotal = $totalWithVat;

        // Keep the JSON summary consistent with the recalculated payment columns.
        $itemOriginalInvoice['discountPayment'] = $discountAmount;
        $itemOriginalInvoice['surcharge'] = $surchargeAmount;
        $itemOriginalInvoice['total_tax'] = $total_tax;

        $result = [
            'id' => $original_invoice->id,
            'status' => $original_invoice->status,
            'valuetotal' => max(0, $valuetotal),
            'items' => json_encode($itemOriginalInvoice),
            'total_tax' => $total_tax,
            'store_id' => $original_invoice->store_id,
            'admin_id' => $original_invoice->admin_id,
            'discount' => $discountAmount,
            'surcharge' => $surchargeAmount,
            'surcharge_percent' => $surchargePercent ?? 0,
            'type_discount' => $typeDiscount,
            'discount_percent' => $typeDiscount === 'percent' ? $discountValue : null,
            'is_senior_discount' => $isSeniorActive,
            'senior_discount_amount' => $seniorAmount,
            'service_charge' => $serviceCharge,
            'service_charge_amount' => $serviceChargeAmount,
            'sub_total_before_discount' => round($billItem['summary']['subtotal_before']),
            'total_incl_vat_before_discount' => $billItem['summary']['total_incl_vat_before_discount'],
        ];

        Log::debug("handleUpdateOriginalInvoice DBG - Result: " . json_encode($result));
        return $result;
    }

    private function updateTableListitemAfterSplit(int $table_id, array $remainingItems)
    {
        $items = isset($remainingItems['item']) ? $remainingItems['item'] : $remainingItems;
        $filteredItems = array_filter($items, fn($item) => ($item['quantity'] ?? 0) > 0);
        $listitem = json_encode(['item' => $filteredItems]);
        Table::where('id', $table_id)->update(['listitem' => $listitem]);
    }

    private function handleUpdateNewInvoice($filters, $originalInvoice = null)
    {
        $store = Store::find($filters['store_id']);
        $isTaxInc = $store ? (bool) ($store->is_tax_included ?? false) : false;
        $totals = $this->computeTotalsFromItems($filters['split_merge_item'], $isTaxInc);

        $items = [
            'item' => $filters['split_merge_item'],
            'discountPayment' => 0,
            'reasonSurcharge' => null,
            'surcharge' => 0,
            'total_tax' => $totals['total_tax'],
        ];

        $splitDiscount = 0;
        $splitTypeDiscount = 'amount';
        $splitDiscountPercent = null;
        if ($originalInvoice) {
            $splitTypeDiscount = $originalInvoice->type_discount ?? 'amount';
            if ($splitTypeDiscount === 'percent') {
                $splitDiscountPercent = (float) ($originalInvoice->discount_percent ?? 0);
            } else {
                $origItems = json_decode($originalInvoice->items, true)['item'] ?? [];
                $origTotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $origItems));
                $splitTotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $filters['split_merge_item']));
                if ($origTotal > 0) {
                    $splitDiscount = round(($originalInvoice->discount ?? 0) * $splitTotal / $origTotal);
                }
            }
        }

        $storeId = (int) $filters['store_id'];
        $userId = $this->resolveUserForStore($storeId, $filters['user_id'] ?? null, $originalInvoice ? $originalInvoice->user_id : null);
        $adminId = $this->resolveUserForStore($storeId, $filters['admin_id'] ?? null, $originalInvoice ? $originalInvoice->admin_id : $userId);
        $paymentCode = 'EDGE-' . date('YmdHis') . '-' . random_int(1000, 9999);

        return [
            "reason" => $filters['reason'] ?? null,
            "customer_id" => $filters['customer_id'] ?? null,
            "items" => json_encode($items),
            "discount" => $splitDiscount,
            "type_discount" => $splitTypeDiscount,
            "discount_percent" => $splitDiscountPercent,
            "surcharge" => $filters['surcharge'] ?? 0,
            "surcharge_percent" => $filters['surcharge_percent'] ?? null,
            "payment_method" => $filters['payment_method'] ?? 'cash',
            "status" => 0, // Pending/Unpaid
            "surcharge_reason" => $filters['surcharge_reason'] ?? null,
            "amount_received" => $filters['amount_received'] ?? null,
            "admin_id" => $adminId,
            "user_id" => $userId,
            "payment_code" => $paymentCode,
            "store_id" => $storeId,
            "table_id" => $filters['target_table_id'],
            "valuetotal" => $totals['total_value'],
            "total_tax" => $totals['total_tax'],
            "is_senior_discount" => $filters['is_senior_discount'] ?? ($originalInvoice->is_senior_discount ?? false),
            "senior_discount_amount" => $filters['senior_discount_amount'] ?? ($originalInvoice->senior_discount_amount ?? 0),
            "service_charge" => $filters['service_charge'] ?? ($originalInvoice->service_charge ?? null),
            "_split_trace_id" => $filters['_split_trace_id'] ?? null,
        ];
    }

    private function paymentCalculationLogContext(array $data, ?Store $store = null): array
    {
        $itemsData = $data['items'] ?? [];
        $itemsData = is_string($itemsData) ? json_decode($itemsData, true) : $itemsData;
        $items = [];

        foreach (($itemsData['item'] ?? []) as $key => $item) {
            $items[$key] = [
                'quantity' => $item['quantity'] ?? null,
                'price' => $item['price'] ?? null,
                'vat' => $item['vat'] ?? null,
                'TotalPrice' => $item['TotalPrice'] ?? null,
                'sub_total_excl_vat' => $item['sub_total_excl_vat'] ?? null,
                'sub_total_incl_vat' => $item['sub_total_incl_vat'] ?? null,
                'discount_percent' => $item['discount_percent'] ?? null,
                'discount_allocated_excl_vat' => $item['discount_allocated_excl_vat'] ?? null,
                'discount_allocated_incl_vat' => $item['discount_allocated_incl_vat'] ?? null,
                'detail_discount' => $item['detail_discount'] ?? null,
                'tax_amount' => $item['tax_amount'] ?? null,
                'net_excl_vat' => $item['net_excl_vat'] ?? null,
                'total_with_vat_after_discount' => $item['total_with_vat_after_discount'] ?? null,
            ];
        }

        return [
            'trace_id' => $data['_split_trace_id'] ?? null,
            'payment_id' => $data['id'] ?? null,
            'payment_code' => $data['payment_code'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'store_time_zone' => $store?->time_zone,
            'is_tax_included' => $store?->is_tax_included,
            'type_discount' => $data['type_discount'] ?? null,
            'discount_percent' => $data['discount_percent'] ?? null,
            'discount' => $data['discount'] ?? null,
            'surcharge_percent' => $data['surcharge_percent'] ?? null,
            'surcharge' => $data['surcharge'] ?? null,
            'service_charge' => $data['service_charge'] ?? null,
            'service_charge_amount' => $data['service_charge_amount'] ?? null,
            'is_senior_discount' => $data['is_senior_discount'] ?? null,
            'senior_discount_amount' => $data['senior_discount_amount'] ?? null,
            'total_tax' => $data['total_tax'] ?? ($data['tax'] ?? null),
            'valuetotal' => $data['valuetotal'] ?? ($data['total'] ?? null),
            'amount_received' => $data['amount_received'] ?? null,
            'json_discountPayment' => $itemsData['discountPayment'] ?? null,
            'json_surcharge' => $itemsData['surcharge'] ?? null,
            'json_total_tax' => $itemsData['total_tax'] ?? null,
            'items' => $items,
        ];
    }

    private function handlePaymentData(&$filters)
    {
        try {
            if (!empty($filters['_skip_recalc'])) {
                return;
            }
            $store = Store::find($filters['store_id']) ?: Store::first();
            $is_tax_included = $store ? ($store->is_tax_included ?? false) : false;

            if (!empty($filters['_split_trace_id'])) {
                Log::info(
                    'EDGE_SPLIT_TRACE handlePaymentData.before',
                    $this->paymentCalculationLogContext($filters, $store)
                );
            }
            
            $items_decode = is_string($filters['items']) ? json_decode($filters['items'], true) : $filters['items'];

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($items_decode)) {
                $items_decode = $filters['items'];
            }

            if (empty($items_decode) || empty($items_decode['item'])) {
                throw new \Exception(__('api.items_empty'));
            }

            $originalOrder = array_keys($items_decode['item']);

            uasort($items_decode['item'], function ($a, $b) {
                $totalA = $a['price'] * $a['quantity'];
                $totalB = $b['price'] * $b['quantity'];
                return $totalB <=> $totalA;
            });

            if (!isset($filters['discount'])) {
                $filters['discount'] = 0;
            }

            $totalDiscount = (float)($filters['discount'] ?? 0);
            $typeDiscount = $filters['type_discount'] ?? 'amount';
            if ($typeDiscount == 'percent') {
                $totalDiscount = (float)($filters['discount_percent'] ?? 0);
            } else {
                $filters['discount_percent'] = null;
            }
            
            $billItem = $is_tax_included 
                ? $this->allocateDiscountTaxIncluded($items_decode['item'], $totalDiscount, $typeDiscount) 
                : $this->allocateDiscountTaxExcluded($items_decode['item'], $totalDiscount, $typeDiscount);
                
            $items_decode['item'] = array_replace(array_flip($originalOrder), $billItem['items']);
            $filters['total_tax'] = $billItem['summary']['total_vat'];
            $filters['sub_total_before_discount'] = round($billItem['summary']['subtotal_before']);
            $filters['discount'] = $billItem['summary']['discount_total'];
            $filters['total_incl_vat_before_discount'] = $billItem['summary']['total_incl_vat_before_discount'];
            $filters['valuetotal'] = round($billItem['summary']['total_with_vat'] + ($filters['surcharge'] ?? 0));

            $timezone = $store ? ($store->time_zone ?? null) : null;
            if ($timezone == 'Asia/Manila') {
                if (!isset($filters['service_charge'])) {
                    $filters['service_charge'] = $store->service_charge ?? 0;
                }

                $baseForSurcharge = $is_tax_included
                    ? $billItem['summary']['total_with_vat']
                    : ($billItem['summary']['subtotal_after'] ?? $billItem['summary']['total_with_vat']);

                if (isset($filters['surcharge_percent']) && (float)$filters['surcharge_percent'] > 0) {
                    $filters['surcharge'] = round($baseForSurcharge * $filters['surcharge_percent'] / 100);
                }

                $filters['service_charge_amount'] = round($baseForSurcharge * $filters['service_charge'] / 100);

                $filters['valuetotal'] = round($billItem['summary']['total_with_vat'] + $filters['service_charge_amount'] + ($filters['surcharge'] ?? 0));

                // Senior Discount (RA 9994) — tính TRƯỚC discount
                if (!empty($filters['is_senior_discount'])) {
                    $subtotal_before = round($billItem['summary']['subtotal_before']);
                    $seniorRate = config('params.senior_discount.rate', 20);
                    $seniorDiscountAmount = round($subtotal_before * $seniorRate / 100);
                    $filters['senior_discount_amount'] = $seniorDiscountAmount;

                    $afterSenior = $subtotal_before - $seniorDiscountAmount;

                    // Recalculate discount on afterSenior (RA 9994: discount applies after senior)
                    if ($typeDiscount === 'percent') {
                        $billItem['summary']['discount_total'] = round($afterSenior * $totalDiscount / 100);
                    }
                    $filters['discount'] = $billItem['summary']['discount_total'];

                    $totalAfterDiscount = $afterSenior - $billItem['summary']['discount_total'];

                    $filters['total_tax'] = 0; // VAT exempt

                    $filters['service_charge_amount'] = round(max(0, $totalAfterDiscount) * $filters['service_charge'] / 100);

                    if (isset($filters['surcharge_percent']) && (float)$filters['surcharge_percent'] > 0) {
                        $filters['surcharge'] = round(max(0, $totalAfterDiscount) * $filters['surcharge_percent'] / 100);
                    }

                    $filters['valuetotal'] = round(max(0, $totalAfterDiscount)
                        + $filters['service_charge_amount']
                        + ($filters['surcharge'] ?? 0));
                }
            } else {
                // Các store khác (Ví dụ Store VN): reset các trường Senior và Service Charge về 0, surcharge là flat amount
                $filters['is_senior_discount'] = false;
                $filters['senior_discount_amount'] = 0;
                $filters['service_charge'] = 0;
                $filters['service_charge_amount'] = 0;
                
                // Surcharge là flat amount
                $surchargeAmount = (float) ($filters['surcharge'] ?? 0);
                $filters['surcharge'] = $surchargeAmount;
                $filters['surcharge_percent'] = 0;

                // Chiết khấu (discount) tính bình thường theo flat hoặc percent
                $discountAmount = 0;
                if ($typeDiscount === 'percent') {
                    $discountAmount = round($filters['sub_total_before_discount'] * ($filters['discount_percent'] ?? 0) / 100);
                } else {
                    $discountAmount = (float) $filters['discount'];
                }
                $filters['discount'] = $discountAmount;
                
                // Thuế VAT
                $total_tax = $filters['total_tax'];

                // valuetotal = total_with_vat + surcharge
                $valuetotal = round($billItem['summary']['total_with_vat'] + $surchargeAmount);
                $filters['valuetotal'] = $valuetotal;
            }

            // Đồng bộ lại vào JSON items
            $items_decode['discountPayment'] = $filters['discount'];
            $items_decode['surcharge'] = $filters['surcharge'];
            $items_decode['total_tax'] = $filters['total_tax'];
            
            $filters['items'] = json_encode($items_decode);

            if (!empty($filters['_split_trace_id'])) {
                Log::info(
                    'EDGE_SPLIT_TRACE handlePaymentData.after',
                    $this->paymentCalculationLogContext($filters, $store)
                );
            }
        } catch (\Throwable $th) {
            Log::error("Error in handlePaymentData Edge: " . $th->getMessage());
            throw $th;
        }
    }

    private function createPaymentLocal(&$data)
    {
        $store = Store::find($data['store_id']);
        if (!empty($data['_split_trace_id'])) {
            Log::info(
                'EDGE_SPLIT_TRACE createPaymentLocal.before',
                $this->paymentCalculationLogContext($data, $store)
            );
        }

        $this->handlePaymentData($data);

        if (!empty($data['_split_trace_id'])) {
            Log::info(
                'EDGE_SPLIT_TRACE createPaymentLocal.after',
                $this->paymentCalculationLogContext($data, $store)
            );
        }
        
        $amountReceived = isset($data['amount_received']) ? round((float) $data['amount_received']) : null;
        $storeTz = \App\Models\Store::whereKey($data['store_id'])->value('time_zone') ?: config('edge_box.timezone', 'Asia/Manila');
        $payment = Payment::create([
            'payment_code' => $data['payment_code'],
            'parent_id' => $data['parent_id'] ?? null,
            'store_id' => $data['store_id'],
            'table_id' => $data['table_id'],
            'customer_id' => $data['customer_id'],
            'items' => $data['items'],
            'paid_date' => now($storeTz),
            'total' => round((float) ($data['valuetotal'] ?? 0)),
            'discount' => (float) ($data['discount'] ?? 0),
            'surcharge' => (float) ($data['surcharge'] ?? 0),
            'surcharge_percent' => (int) ($data['surcharge_percent'] ?? 0),
            'surcharge_reason' => $data['surcharge_reason'] ?? null,
            'type_discount' => $data['type_discount'] ?? 'amount',
            'discount_percent' => (int) ($data['discount_percent'] ?? 0),
            'tax' => (float) ($data['total_tax'] ?? 0),
            'final_total' => round((float) ($data['valuetotal'] ?? 0)),
            'amount_received' => $amountReceived,
            'payment_method' => $data['payment_method'] ?? 'cash',
            'note' => $data['reason'] ?? null,
            'status' => $data['status'],
            'user_id' => $data['user_id'] ?? 1,
            'admin_id' => $data['admin_id'] ?? 1,
            'is_senior_discount' => $data['is_senior_discount'] ?? false,
            'senior_discount_amount' => $data['senior_discount_amount'] ?? 0,
            'service_charge' => $data['service_charge'] ?? 0,
            'service_charge_amount' => $data['service_charge_amount'] ?? 0,
            'sub_total_before_discount' => (float) ($data['sub_total_before_discount'] ?? 0),
            'total_incl_vat_before_discount' => (float) ($data['total_incl_vat_before_discount'] ?? 0),
        ]);

        if (!empty($data['_split_trace_id'])) {
            $savedPayment = $payment->toArray();
            $savedPayment['_split_trace_id'] = $data['_split_trace_id'];
            Log::info(
                'EDGE_SPLIT_TRACE createPaymentLocal.saved',
                $this->paymentCalculationLogContext($savedPayment, $store)
            );
        }

        $productList = json_decode($payment->items, true);
        foreach ($productList['item'] as $key => $value) {
            $detailPrice = (float) ($value['price'] ?? 0);
            $detailQty = (int) ($value['quantity'] ?? 1);
            $detailTotal = (float) ($value['TotalPrice'] ?? ($value['total'] ?? ($detailPrice * $detailQty)));

            // Lấy thẳng từ kết quả của allocateDiscountTaxIncluded/Excluded
            $detailDiscountExcl = (float) ($value['detail_discount_excluding_tax'] ?? 0);
            $detailNetExcl = (float) ($value['discounted_price_excluding_tax'] ?? $detailTotal);
            $detailTaxAmt = (float) ($value['tax_amount'] ?? 0);
            $unitPriceExcl = (float) ($value['unit_price_excluding_tax'] ?? round($detailNetExcl / $detailQty));

            PaymentDetail::create([
                'payment_id' => $payment->id,
                'product_id' => $value['id'],
                'product_key' => $key,
                'quantity' => $detailQty,
                'printed_quantity' => $value['printed_quantity'] ?? 0,
                'price' => $detailPrice,
                'total' => $detailTotal,
                'note' => $this->buildPaymentDetailNote($value),
                'product_extra' => !empty($value['extra_product_list']) ? json_encode($value['extra_product_list']) : null,
                'optional_products' => !empty($value['optional_products']) ? json_encode($value['optional_products']) : null,
                'detail_discount' => (float) ($value['detail_discount'] ?? 0),
                'detail_discount_excluding_tax' => $detailDiscountExcl,
                'discounted_price_excluding_tax' => $detailNetExcl,
                'tax_amount' => $detailTaxAmt,
                'unit_price_excluding_tax' => $unitPriceExcl,
                'admin_id' => $payment->admin_id,
                'store_id' => $payment->store_id,
            ]);
        }
        return ['status' => true, 'payment' => $payment];
    }

    private function updatePaymentLocal(&$data, bool $splitInvoice = false)
    {
        if (!$splitInvoice) {
            $this->handlePaymentData($data);
        }
        
        $payment = Payment::find($data['id']);
        if (!$payment) {
            throw new \RuntimeException('Payment not found: ' . $data['id']);
        }
        $payment->update([
            'items' => $data['items'],
            'total' => $data['valuetotal'],
            'discount' => $data['discount'] ?? $payment->discount,
            'type_discount' => $data['type_discount'] ?? $payment->type_discount,
            'discount_percent' => $data['discount_percent'] ?? $payment->discount_percent,
            'surcharge' => $data['surcharge'] ?? $payment->surcharge,
            'surcharge_percent' => $data['surcharge_percent'] ?? $payment->surcharge_percent,
            'tax' => $data['total_tax'],
            'final_total' => $data['valuetotal'],
            'amount_received' => isset($data['amount_received']) ? round((float) $data['amount_received']) : $payment->amount_received,
            'is_senior_discount' => $data['is_senior_discount'] ?? $payment->is_senior_discount,
            'senior_discount_amount' => $data['senior_discount_amount'] ?? $payment->senior_discount_amount,
            'service_charge' => $data['service_charge'] ?? $payment->service_charge,
            'service_charge_amount' => $data['service_charge_amount'] ?? $payment->service_charge_amount,
            'sub_total_before_discount' => $data['sub_total_before_discount'] ?? $payment->sub_total_before_discount,
            'total_incl_vat_before_discount' => $data['total_incl_vat_before_discount'] ?? $payment->total_incl_vat_before_discount,
        ]);

        $productList = json_decode($data['items'], true);
        $listPaymentDetail = PaymentDetail::where("payment_id", $data['id'])->pluck('product_key')->toArray();

        foreach ($productList['item'] as $key => $value) {
            $position = array_search((string) $key, $listPaymentDetail);
            if ($position !== false) {
                unset($listPaymentDetail[$position]);
            }
            $detailPrice = (float) ($value['price'] ?? 0);
            $detailQty = (int) ($value['quantity'] ?? 1);
            $detailTotal = (float) ($value['TotalPrice'] ?? ($value['total'] ?? ($detailPrice * $detailQty)));
            $detailVat = (float) ($value['vat'] ?? 0);
            $detailDiscountExcl = (float) ($value['detail_discount_excluding_tax'] ?? 0);
            $detailNetExcl = (float) ($value['discounted_price_excluding_tax'] ?? 0);
            $detailTaxAmt = (float) ($value['tax_amount'] ?? 0);
            if ($detailNetExcl == 0 && $detailVat > 0) {
                $detailNetExcl = round($detailTotal / (1 + $detailVat / 100));
            } elseif ($detailNetExcl == 0) {
                $detailNetExcl = $detailTotal;
            }
            if ($detailTaxAmt == 0 && $detailVat > 0) {
                $detailTaxAmt = $detailTotal - $detailNetExcl;
            }
            $existingDetail = PaymentDetail::where('payment_id', $data['id'])->where('product_key', $key)->first();
            $detailPrintedQty = array_key_exists('printed_quantity', $value)
                ? (int) ($value['printed_quantity'] ?? 0)
                : ($existingDetail ? min((int) $existingDetail->printed_quantity, $detailQty) : 0);
            PaymentDetail::updateOrCreate(
                [
                    'payment_id' => $data['id'],
                    'product_key' => $key,
                ],
                [
                    'product_id' => $value['id'],
                    'product_key' => $key,
                    'quantity' => $detailQty,
                    'printed_quantity' => $detailPrintedQty,
                    'price' => $detailPrice,
                    'total' => $detailTotal,
                    'note' => $this->buildPaymentDetailNote($value),
                    'product_extra' => !empty($value['extra_product_list']) ? json_encode($value['extra_product_list']) : null,
                    'optional_products' => !empty($value['optional_products']) ? json_encode($value['optional_products']) : null,
                    'detail_discount' => (float) ($value['detail_discount'] ?? 0),
                    'detail_discount_excluding_tax' => $detailDiscountExcl,
                    'discounted_price_excluding_tax' => $detailNetExcl,
                    'tax_amount' => $detailTaxAmt,
                    'unit_price_excluding_tax' => (float) ($value['unit_price_excluding_tax'] ?? round($detailNetExcl / $detailQty)),
                    'admin_id' => $data['admin_id'] ?? 1,
                    'store_id' => $data['store_id'],
                ]
            );
        }
        if (!empty($listPaymentDetail)) {
            PaymentDetail::where('payment_id', $data['id'])->whereIn('product_key', array_values($listPaymentDetail))->delete();
        }

        if (!empty($data['_split_trace_id'])) {
            $this->logSplitPaymentInvariantWarnings($payment->fresh(), $data);
        }

        return $payment;
    }

    private function logSplitPaymentInvariantWarnings(Payment $payment, array $data): void
    {
        $items = json_decode($payment->items, true)['item'] ?? [];
        $itemQuantity = array_sum(array_map(
            static fn (array $item): int => (int) ($item['quantity'] ?? 0),
            $items
        ));
        $detailQuantity = (int) $payment->details()->sum('quantity');
        $mismatches = [];

        $checks = [
            'quantity' => [$itemQuantity, $detailQuantity],
            'sub_total_before_discount' => [
                (float) ($data['sub_total_before_discount'] ?? $payment->sub_total_before_discount),
                (float) $payment->sub_total_before_discount,
            ],
            'total_tax' => [
                (float) ($data['total_tax'] ?? $payment->tax),
                (float) $payment->tax,
            ],
            'valuetotal' => [
                (float) ($data['valuetotal'] ?? $payment->final_total),
                (float) $payment->final_total,
            ],
        ];

        foreach ($checks as $field => [$expected, $actual]) {
            if (abs($expected - $actual) > 0.01) {
                $mismatches[$field] = compact('expected', 'actual');
            }
        }

        if (!empty($mismatches)) {
            Log::warning('EDGE_SPLIT_TRACE payment.invariant_mismatch', [
                'trace_id' => $data['_split_trace_id'],
                'payment_id' => $payment->id,
                'payment_code' => $payment->payment_code,
                'mismatches' => $mismatches,
            ]);
        }
    }

    private function handleData4TargetInvoice($filters, $targetInvoice)
    {
        $itemsOftargetInvoice = $this->getPaymentItems($targetInvoice);
        $item = $itemsOftargetInvoice['item'] ?? [];
        $storeTarget = Store::find($targetInvoice['store_id']);
        $isTaxInc = $storeTarget->is_tax_included ?? 0;
        foreach ($filters['split_merge_item'] as $key => $value) {
            if (empty($item[$key])) {
                $item[$key] = $value;
            } else {
                $item[$key]['quantity'] += $value['quantity'];
            }
            $item[$key]['TotalPrice'] = $isTaxInc
                ? intval($item[$key]['quantity']) * floatval($item[$key]['price'])
                : $this->calculateTotalAfterTax($item[$key], false)['total'];
        }

        $totals = $this->computeTotalsFromItems($item, $isTaxInc);
        $total_tax = $totals['total_tax'];
        $total_value = $totals['total_value'];

        $discountAmount = (float) ($targetInvoice['discount'] ?? 0);
        $surchargeAmount = (float) ($targetInvoice['surcharge'] ?? 0);
        $serviceChargePercent = (float) ($targetInvoice['service_charge'] ?? 0);
        $scBaseTotal = $total_value - $total_tax;

        if (($storeTarget->time_zone ?? null) === 'Asia/Manila') {
            $seniorAmount = (float) ($targetInvoice['senior_discount_amount'] ?? round($scBaseTotal * 20 / 100));
            $isSeniorActive = !empty($targetInvoice['is_senior_discount']) && $seniorAmount > 0;
        } else {
            $seniorAmount = 0;
            $isSeniorActive = false;
        }

        $seniorDeduction = $isSeniorActive ? $seniorAmount : 0;
        $afterSenior = $scBaseTotal - $seniorDeduction;

        // Recalculate discount on afterSenior for percent (RA 9994)
        $typeDiscount = $targetInvoice['type_discount'] ?? 'amount';
        if ($isSeniorActive && $typeDiscount === 'percent') {
            $discPct = (float) ($targetInvoice['discount_percent'] ?? 0);
            $discountAmount = round($afterSenior * $discPct / 100);
        }

        if ($isSeniorActive) {
            $chargeBase = max(0, $scBaseTotal - $seniorDeduction - $discountAmount);
        } elseif ($isTaxInc) {
            $chargeBase = max(0, $total_value - $discountAmount);
        } else {
            $chargeBase = max(0, $scBaseTotal - $discountAmount);
        }
        $serviceChargeAmount = round($chargeBase * $serviceChargePercent / 100);

        if ($isSeniorActive) {
            $total_tax = 0; // VAT exempt
        }

        $itemsOftargetInvoice['total_tax'] = $total_tax;
        $itemsOftargetInvoice['item'] = $item;
        return [
            'id' => $targetInvoice['id'],
            'status' => $targetInvoice['status'],
            'valuetotal' => max(0, $afterSenior - $discountAmount + ($isSeniorActive ? 0 : $total_tax) + $surchargeAmount + $serviceChargeAmount),
            'items' => json_encode($itemsOftargetInvoice),
            'total_tax' => $total_tax,
            'store_id' => $targetInvoice['store_id'],
            'admin_id' => $targetInvoice['admin_id'],
            'discount' => $discountAmount,
            'surcharge' => $surchargeAmount,
            'is_senior_discount' => $targetInvoice['is_senior_discount'] ?? false,
            'senior_discount_amount' => $targetInvoice['senior_discount_amount'] ?? 0,
            'service_charge' => $serviceChargePercent,
            'service_charge_amount' => $serviceChargeAmount,
        ];
    }

    private function updateTableAfterSplitMerge($table_id, $paymentAfterSplitMerge)
    {
        Table::where('id', $table_id)->update(['listitem' => $paymentAfterSplitMerge['items']]);
    }

    private function handleData4MergeInvoice($original_invoice, $target_invoice)
    {
        $originalDecoded = $this->getPaymentItems($original_invoice);
        $targetDecoded = $this->getPaymentItems($target_invoice);

        $originalItems = $originalDecoded['item'] ?? [];
        $targetItems = $targetDecoded['item'] ?? [];
        $storeMerge = Store::find($target_invoice->store_id);
        $isTaxInc = $storeMerge->is_tax_included ?? 0;

        foreach ($originalItems as $key => $value) {
            if (empty($targetItems[$key])) {
                $targetItems[$key] = $value;
            } else {
                $targetItems[$key]['quantity'] += $value['quantity'];
            }
            $targetItems[$key]['TotalPrice'] = $isTaxInc
                ? intval($targetItems[$key]['quantity']) * floatval($targetItems[$key]['price'])
                : $this->calculateTotalAfterTax($targetItems[$key], false)['total'];
        }

        $totals = $this->computeTotalsFromItems($targetItems, $isTaxInc);
        $total_tax = $totals['total_tax'];
        $total_value = $totals['total_value'];

        $discountAmount = (float) ($target_invoice->discount ?? 0);
        $surchargeAmount = (float) ($target_invoice->surcharge ?? 0);
        $serviceChargePercent = (float) ($target_invoice->service_charge ?? 0);
        $scBaseTotal = $total_value - $total_tax;

        if (($storeMerge->time_zone ?? null) === 'Asia/Manila') {
            $seniorAmount = (float) ($target_invoice->senior_discount_amount ?? round($scBaseTotal * 20 / 100));
            $isSeniorActive = !empty($target_invoice->is_senior_discount) && $seniorAmount > 0;
        } else {
            $seniorAmount = 0;
            $isSeniorActive = false;
        }

        $seniorDeduction = $isSeniorActive ? $seniorAmount : 0;
        $afterSenior = $scBaseTotal - $seniorDeduction;

        // Recalculate discount on afterSenior for percent (RA 9994)
        $typeDiscount = $target_invoice->type_discount ?? 'amount';
        if ($isSeniorActive && $typeDiscount === 'percent') {
            $discPct = (float) ($target_invoice->discount_percent ?? 0);
            $discountAmount = round($afterSenior * $discPct / 100);
        }

        if ($isSeniorActive) {
            $chargeBase = max(0, $scBaseTotal - $seniorDeduction - $discountAmount);
        } elseif ($isTaxInc) {
            $chargeBase = max(0, $total_value - $discountAmount);
        } else {
            $chargeBase = max(0, $scBaseTotal - $discountAmount);
        }
        $serviceChargeAmount = round($chargeBase * $serviceChargePercent / 100);

        if ($isSeniorActive) {
            $total_tax = 0; // VAT exempt
        }

        $itemsTargetInvoice = $targetDecoded;
        $itemsTargetInvoice['total_tax'] = $total_tax;
        $itemsTargetInvoice['item'] = $targetItems;

        return [
            'id' => $target_invoice->id,
            'status' => $target_invoice->status,
            'valuetotal' => max(0, $afterSenior - $discountAmount + ($isSeniorActive ? 0 : $total_tax) + $surchargeAmount + $serviceChargeAmount),
            'items' => json_encode($itemsTargetInvoice),
            'total_tax' => $total_tax,
            'store_id' => $target_invoice->store_id,
            'admin_id' => $target_invoice->admin_id,
            'discount' => $discountAmount,
            'surcharge' => $surchargeAmount,
            'is_senior_discount' => $target_invoice->is_senior_discount ?? false,
            'senior_discount_amount' => $target_invoice->senior_discount_amount ?? 0,
            'service_charge' => $serviceChargePercent,
            'service_charge_amount' => $serviceChargeAmount,
        ];
    }

    private function buildPaymentDetailNote(array $item): ?string
    {
        $parts = [];
        if (!empty($item['note'])) {
            $parts[] = $item['note'];
        }

        if (!empty($item['product_types']) && is_array($item['product_types'])) {
            foreach ($item['product_types'] as $productType) {
                if (!empty($productType['productTypeValue'])) {
                    $parts[] = $productType['productTypeValue'];
                }
            }
        }

        return !empty($parts) ? implode(', ', $parts) : null;
    }

    private function refreshPaymentWithDetails($id)
    {
        return Payment::with('details')->find($id);
    }

    /**
     * Helper to retrieve items array for a payment.
     * Safely falls back to active Table listitem or PaymentDetails if items column is empty.
     */
    private function getPaymentItems(Payment $payment): array
    {
        $itemsJson = $payment->items;
        Log::debug("getPaymentItems DBG - Payment ID: {$payment->id}, initial itemsJson: " . json_encode($itemsJson));

        // 1. Decode first if not empty
        $decoded = null;
        if (!empty($itemsJson)) {
            $decoded = json_decode($itemsJson, true);
            Log::debug("getPaymentItems DBG - Decoded initial itemsJson: " . json_encode($decoded));
        }

        // 2. If empty or no 'item' key, try Table listitem fallback
        if ((empty($decoded) || empty($decoded['item'])) && !empty($payment->table_id)) {
            $table = Table::find($payment->table_id);
            if ($table && !empty($table->listitem)) {
                Log::debug("getPaymentItems DBG - Table ID: {$payment->table_id} listitem: " . json_encode($table->listitem));
                $decoded = json_decode($table->listitem, true);
            } else {
                Log::debug("getPaymentItems DBG - Table ID: {$payment->table_id} listitem is empty or table not found");
            }
        }

        // 3. Fallback to PaymentDetails if still empty
        if (empty($decoded) || empty($decoded['item'])) {
            Log::debug("getPaymentItems DBG - Falling back to PaymentDetails");
            $details = PaymentDetail::with('product')->where('payment_id', $payment->id)->get();
            $itemsArray = [];
            foreach ($details as $detail) {
                $prodKey = $detail->product_key;
                if (empty($prodKey)) {
                    $prodKey = ($detail->product->product_code ?? 'PROD') . '.0.0.0.0';
                }
                $itemsArray[$prodKey] = [
                    'id' => (string) $detail->product_id,
                    'product_code' => $detail->product->product_code ?? '',
                    'title' => $detail->product->title ?? ($detail->product_name ?? ''),
                    'quantity' => (string) $detail->quantity,
                    'price' => (string) $detail->price,
                    'vat' => (string) ($detail->product->vat ?? 0),
                    'image' => $detail->product->image ?? 'assets/images/image_not_found.png',
                    'note' => $detail->note ?? '',
                    'price_after_tax' => (string) ($detail->product->price_after_tax ?? $detail->price),
                    'product_types' => null,
                    'number_of_options' => 0,
                    'TotalPrice' => $detail->total,
                ];
            }
            $decoded = [
                'item' => $itemsArray,
                'discountPayment' => $payment->discount,
                'reasonSurcharge' => $payment->surcharge_reason,
                'surcharge' => $payment->surcharge,
                'total_tax' => $payment->tax,
            ];
            Log::debug("getPaymentItems DBG - Reconstructed from details: " . json_encode($decoded));
        }

        return $decoded;
    }

    private function resolveUserForStore(int $storeId, $requestedUserId = null, $originalUserId = null): int
    {
        if ($requestedUserId && \App\Models\User::where('store_id', $storeId)->where('id', (int) $requestedUserId)->exists()) {
            return (int) $requestedUserId;
        }
        if ($originalUserId && \App\Models\User::where('store_id', $storeId)->where('id', (int) $originalUserId)->exists()) {
            return (int) $originalUserId;
        }
        $storeAdmin = \App\Models\User::where('store_id', $storeId)->where('role', 'admin')->orderBy('id')->first();
        if ($storeAdmin) {
            return (int) $storeAdmin->id;
        }
        $storeUser = \App\Models\User::where('store_id', $storeId)->orderBy('id')->first();
        return $storeUser ? (int) $storeUser->id : ($requestedUserId ? (int) $requestedUserId : 1);
    }
}
