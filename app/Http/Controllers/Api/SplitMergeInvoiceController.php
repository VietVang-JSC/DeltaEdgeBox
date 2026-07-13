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

            $payments->load(['table' => function($query) {
                $query->select('id', 'name as tablename', 'store_id', 'payment_id');
            }, 'customer' => function($query) {
                $query->select('id', 'name', 'store_id');
            }]);

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'Lấy danh sách hóa đơn thành công',
                'data' => $payments,
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge getListInvoice failed: ' . $th->getMessage());
            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => 'Đã có lỗi xảy ra',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function splitInvoice(Request $request)
    {
        $filters = $request->all();
        $storeId = (int) $request->input('store_id', config('app.store_id'));
        $filters['store_id'] = $storeId;

        DB::beginTransaction();
        try {
            $originalInvoice = Payment::with('details')
                ->where('store_id', $storeId)
                ->find($filters['original_invoice_id']);
            if (!$originalInvoice) {
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 404, 'message' => 'Không tìm thấy hóa đơn gốc'], 404);
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
                return response()->json(['status' => false, 'status_code' => 400, 'message' => 'Không xác định được phương thức tách hóa đơn'], 400);
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
            return response()->json(['status' => false, 'status_code' => 500, 'message' => 'Đã có lỗi xảy ra: ' . $th->getMessage()], 500);
        }
    }

    private function splitInvoiceTargetTable($filters, $originalInvoice)
    {
        $itemOriginalInvoice = $this->getPaymentItems($originalInvoice);
        $handleData = $this->handleDataSplitInvoice($itemOriginalInvoice['item'], $filters['split_merge_item'], $filters['store_id']);
        if (!$handleData['status']) {
            return $handleData;
        }

        $paramUpdateOriginalInvoice = $this->handleUpdateOriginalInvoice($itemOriginalInvoice, $originalInvoice);
        $this->updatePaymentLocal($paramUpdateOriginalInvoice);

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
            return ['status' => false, 'status_code' => 404, 'message' => 'Không tìm thấy bàn đích'];
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
            'message' => 'Tách hóa đơn thành công',
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
            return ['status' => false, 'status_code' => 404, 'message' => 'Không tìm thấy hóa đơn cần chia'];
        }

        $itemOriginalInvoice = $this->getPaymentItems($originalInvoice);
        $handleData = $this->handleDataSplitInvoice($itemOriginalInvoice['item'], $filters['split_merge_item'], $filters['store_id']);
        if (!$handleData['status']) {
            return $handleData;
        }

        $paramUpdateOriginalInvoice = $this->handleUpdateOriginalInvoice($itemOriginalInvoice, $originalInvoice);
        $this->updatePaymentLocal($paramUpdateOriginalInvoice);

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
            'message' => 'Tách hóa đơn thành công',
            'data' => [
                $this->refreshPaymentWithDetails($originalInvoice->id),
                $this->refreshPaymentWithDetails($checkTargetInvoice->id),
            ]
        ];
    }

    private function createInvoiceFromOriginal($filters, $originalInvoice)
    {
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

        $paramUpdateOriginalInvoice = $this->handleUpdateOriginalInvoice($itemOriginalInvoice, $originalInvoice);
        $this->updatePaymentLocal($paramUpdateOriginalInvoice);

        if (!empty($originalInvoice->table_id)) {
            $this->updateTableListitemAfterSplit($originalInvoice->table_id, $itemOriginalInvoice);
        }

        $dataItem = [];
        $dataItem['item'] = $filters['split_merge_item'];
        $dataItem['discountPayment'] = $filters['discount'] ?? null;
        $dataItem['reasonSurcharge'] = $filters['reasonSurcharge'] ?? null;
        $dataItem['surcharge'] = $filters['surcharge'] ?? null;
        $items = json_encode($dataItem);

        $store = Store::find($filters['store_id']);
        $isTaxInc = $store->is_tax_included ?? 0;
        $totals = $this->computeTotalsFromItems($filters['split_merge_item'], $isTaxInc);
        $total_tax_pre = $totals['total_tax']; // pre-discount tax (from items directly)
        $total_value = $totals['total_value'];

        // Use original - remain for ALL values to ensure sum = original 100% (prevent rounding)
        $typeDiscount = $originalInvoice->type_discount ?? 'amount';
        $discountAmount = max(0, (float) ($originalInvoice->discount ?? 0) - (float) ($paramUpdateOriginalInvoice['discount'] ?? 0));
        if (isset($filters['discount'])) {
            $discountAmount = (float) $filters['discount'];
            $typeDiscount = $filters['type_discount'] ?? $typeDiscount;
        }

        if (($store->time_zone ?? null) === 'Asia/Manila') {
            $isSenior = (bool) ($filters['is_senior_discount'] ?? ($originalInvoice->is_senior_discount ?? false));
            $inheritedSeniorAmount = max(0, (float) ($originalInvoice->senior_discount_amount ?? 0) - (float) ($paramUpdateOriginalInvoice['senior_discount_amount'] ?? 0));
            if ($isSenior) {
                $seniorAmount = (float) round(($total_value - $total_tax_pre) * 20 / 100);
            } else {
                $seniorAmount = 0;
            }
        } else {
            $seniorAmount = 0;
            $isSenior = false;
        }
        $isSeniorActive = $isSenior && $seniorAmount > 0;
        $surchargePercent = (float) ($filters['surcharge_percent'] ?? ($originalInvoice->surcharge_percent ?? 0));
        $surchargeAmount = max(0, (float) ($originalInvoice->surcharge ?? 0) - (float) ($paramUpdateOriginalInvoice['surcharge'] ?? 0));
        if (isset($filters['surcharge'])) {
            $surchargeAmount = (float) $filters['surcharge'];
        }
        $serviceChargePercent = (float) ($filters['service_charge'] ?? ($originalInvoice->service_charge ?? 0));
        // Calculate service charge for split bill independently (same formula as handleUpdateOriginalInvoice)
        // Using subtraction (orig - remain) can result in 0 when original invoice has service_charge_amount=0
        if ($serviceChargePercent > 0) {
            $scBaseTotalForCharge = $total_value - $total_tax_pre;
            if ($isSeniorActive) {
                $chargeBaseSplit = max(0, $scBaseTotalForCharge - $seniorAmount - $discountAmount);
            } elseif ($isTaxInc) {
                $chargeBaseSplit = max(0, $total_value - $discountAmount);
            } else {
                $chargeBaseSplit = max(0, $scBaseTotalForCharge - $discountAmount);
            }
            $serviceChargeAmount = round($chargeBaseSplit * $serviceChargePercent / 100);
        } else {
            $serviceChargeAmount = 0;
        }
        $total_tax = $isSeniorActive ? 0 : max(0, (float) ($originalInvoice->tax ?? 0) - (float) ($paramUpdateOriginalInvoice['total_tax'] ?? 0));

        $scBaseTotalSplitFinal = $total_value - $total_tax_pre;
        $isNewSeniorDiscount = $isSeniorActive && empty($originalInvoice->is_senior_discount);
        if ($isSeniorActive) {
            $total_tax = 0;
            $afterSeniorSplit = max(0, $scBaseTotalSplitFinal - $seniorAmount);
            $valuetotal = max(0, $afterSeniorSplit - $discountAmount + $surchargeAmount + $serviceChargeAmount);
        } elseif ($isTaxInc) {
            $valuetotal = max(0, $total_value - $discountAmount + $surchargeAmount + $serviceChargeAmount);
        } else {
            $valuetotal = max(0, $scBaseTotalSplitFinal - $discountAmount + $total_tax + $surchargeAmount + $serviceChargeAmount);
        }

        $paymentCode = 'EDGE-' . date('YmdHis') . '-' . random_int(1000, 9999);

        $paramCreatePayment = [
            "reason" => $filters['reason'] ?? null,
            "customer_id" => $filters['customer_id'] ?? null,
            "items" => $items,
            "discount" => $discountAmount,
            "type_discount" => $typeDiscount,
            "discount_percent" => $typeDiscount === 'percent' ? (float) ($originalInvoice->discount_percent ?? 0) : null,
            "surcharge" => $surchargeAmount,
            "payment_method" => $filters['payment_method'] ?? 'cash',
            "status" => 1, // Paid
            "surcharge_reason" => $filters['surcharge_reason'] ?? null,
            "amount_received" => $filters['amount_received'] ?? null,
            "admin_id" => $filters['admin_id'] ?? 1,
            "user_id" => $originalInvoice->user_id,
            "payment_code" => $paymentCode,
            "store_id" => $filters['store_id'],
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
        ];

        $createPayment = $this->createPaymentLocal($paramCreatePayment);
        if (!$createPayment['status']) {
            return $createPayment;
        }

        return [
            'status' => true,
            'status_code' => 200,
            'message' => 'Tách hóa đơn thành công',
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
            $originalInvoice = Payment::where('store_id', $storeId)
                ->find($filters['original_invoice_id']);
            if (!$originalInvoice) {
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 404, 'message' => 'Không tìm thấy hóa đơn gốc'], 404);
            }
            if ($originalInvoice->status !== 0) { // Pending/Unpaid
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 400, 'message' => 'Hóa đơn đã được thanh toán hoặc đã bị xóa'], 400);
            }

            $targetInvoice = Payment::where('store_id', $storeId)
                ->find($filters['target_invoice_id']);
            if (!$targetInvoice) {
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 404, 'message' => 'Không tìm thấy hóa đơn tách'], 404);
            }
            if ($targetInvoice->status !== 0) { // Pending/Unpaid
                DB::rollBack();
                return response()->json(['status' => false, 'status_code' => 400, 'message' => 'Hóa đơn đã được thanh toán hoặc đã bị xóa'], 400);
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
            return response()->json(['status' => true, 'status_code' => 200, 'message' => 'Ghép hóa đơn thành công']);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Edge mergeInvoice failed: ' . $th->getMessage());
            return response()->json(['status' => false, 'status_code' => 500, 'message' => 'Đã có lỗi xảy ra: ' . $th->getMessage()], 500);
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

    private function handleUpdateOriginalInvoice($itemOriginalInvoice, $original_invoice)
    {
        $storeOrig = Store::find($original_invoice->store_id);
        $isTaxInc = $storeOrig->is_tax_included ?? 0;
        $totals = $this->computeTotalsFromItems($itemOriginalInvoice['item'], $isTaxInc);
        $total_tax = $totals['total_tax'];
        $total_value = $totals['total_value'];
        $itemOriginalInvoice['total_tax'] = $total_tax;

        // Proportional discount: re-allocate discount to remaining items (same as cloud)
        $typeDiscount = $original_invoice->type_discount ?? 'amount';
        $discountPct = (float) ($original_invoice->discount_percent ?? 0);
        $discountAmount = (float) ($original_invoice->discount ?? 0);
        if ($typeDiscount === 'percent') {
            // keep percent value for recalculation after scBaseTotal/afterSenior
        } else {
            $origItems = json_decode($original_invoice->items, true)['item'] ?? [];
            $origTotal = 0;
            $remainTotal = 0;
            foreach ($origItems as $k => $v) {
                $origTotal += $v['price'] * $v['quantity'];
            }
            foreach ($itemOriginalInvoice['item'] as $k => $v) {
                $remainTotal += $v['price'] * $v['quantity'];
            }
            if ($origTotal > 0) {
                $discountAmount = round(($original_invoice->discount ?? 0) * $remainTotal / $origTotal);
            }
        }
        $scBaseTotal = $total_value - $total_tax;

        if (($storeOrig->time_zone ?? null) === 'Asia/Manila') {
            $isSeniorActive = (bool) ($original_invoice->is_senior_discount ?? false);
            $seniorAmount = $isSeniorActive ? (float) round($scBaseTotal * 20 / 100) : 0;
            if ($isSeniorActive && $seniorAmount == 0) {
                $isSeniorActive = false;
            }
        } else {
            $seniorAmount = 0;
            $isSeniorActive = false;
        }
        $seniorDeduction = $isSeniorActive ? $seniorAmount : 0;
        $afterSenior = $scBaseTotal - $seniorDeduction;

        // Recalculate discount percent on appropriate base
        if ($typeDiscount === 'percent') {
            $discountAmount = $isSeniorActive
                ? round($afterSenior * $discountPct / 100)
                : ($isTaxInc ? round($total_value * $discountPct / 100) : round($scBaseTotal * $discountPct / 100));
        }

        // Recalculate tax on after-discount base (matching cloud)
        if (!$isSeniorActive && $discountAmount > 0) {
            $newTotalTax = 0;
            $totalBase = $isTaxInc ? $total_value : $scBaseTotal;
            foreach ($itemOriginalInvoice['item'] as $item) {
                $q = (int) ($item['quantity'] ?? 1);
                $p = (float) ($item['price'] ?? 0);
                $v = (float) ($item['vat'] ?? 0);
                $lineBase = $q * $p;
                $ratio = $totalBase > 0 ? $lineBase / $totalBase : 0;
                $itemDisc = round($discountAmount * $ratio);
                if ($isTaxInc) {
                    $afterDiscIncl = $lineBase - $itemDisc;
                    $vatDiv = 1 + $v / 100;
                    $netExcl = $vatDiv > 0 ? round($afterDiscIncl / $vatDiv) : $afterDiscIncl;
                    $newTotalTax += $afterDiscIncl - $netExcl;
                } else {
                    $afterDiscExcl = $lineBase - $itemDisc;
                    $newTotalTax += round($afterDiscExcl * $v / 100);
                }
            }
            $total_tax = $newTotalTax;
        }

        // Charge base: SD → exVAT after senior+discount; tax-inc+noSD → incVAT after discount; tax-exc+noSD → exVAT after discount
        if ($isSeniorActive) {
            $chargeBase = max(0, $scBaseTotal - $seniorDeduction - $discountAmount);
        } elseif ($isTaxInc) {
            $chargeBase = max(0, $total_value - $discountAmount);
        } else {
            $chargeBase = max(0, $scBaseTotal - $discountAmount);
        }

        // Surcharge: proportion from original (nếu flat) hoặc recalc từ percent (nếu có)
        $surchargePercent = $original_invoice->surcharge_percent ?? 0;
        $surchargeAmount = (float) ($original_invoice->surcharge ?? 0);
        if ($surchargePercent > 0) {
            $surchargeAmount = round($chargeBase * $surchargePercent / 100);
        } elseif ($surchargeAmount > 0) {
            $origItems = json_decode($original_invoice->items, true)['item'] ?? [];
            $origTotal = 0;
            foreach ($origItems as $k => $v) { $origTotal += $v['price'] * $v['quantity']; }
            $remainTotal = 0;
            foreach ($itemOriginalInvoice['item'] as $k => $v) { $remainTotal += $v['price'] * $v['quantity']; }
            if ($origTotal > 0) {
                $surchargeAmount = round($surchargeAmount * $remainTotal / $origTotal);
            }
        }

        $serviceChargePercent = (float) ($original_invoice->service_charge ?? 0);
        $serviceChargeAmount = round($chargeBase * $serviceChargePercent / 100);

        if ($isSeniorActive) {
            $total_tax = 0; // VAT exempt
        }

        return [
            'id' => $original_invoice->id,
            'status' => $original_invoice->status,
            'valuetotal' => max(0, $afterSenior - $discountAmount + ($isSeniorActive ? 0 : $total_tax) + $surchargeAmount + $serviceChargeAmount),
            'items' => json_encode($itemOriginalInvoice),
            'total_tax' => $total_tax,
            'store_id' => $original_invoice->store_id,
            'admin_id' => $original_invoice->admin_id,
            'discount' => $discountAmount,
            'surcharge' => $surchargeAmount,
            'surcharge_percent' => $original_invoice->surcharge_percent ?? 0,
            'is_senior_discount' => $original_invoice->is_senior_discount ?? false,
            'senior_discount_amount' => $seniorAmount,
            'service_charge' => $serviceChargePercent,
            'service_charge_amount' => $serviceChargeAmount,
        ];
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
        $dataItem = [];
        $dataItem['item'] = $filters['split_merge_item'];
        $dataItem['discountPayment'] = $filters['discount'] ?? null;
        $dataItem['reasonSurcharge'] = $filters['reasonSurcharge'] ?? null;
        $dataItem['surcharge'] = $filters['surcharge'] ?? null;
        $items = json_encode($dataItem);

        $store = Store::find($filters['store_id']);
        $isTaxInc = $store->is_tax_included ?? 0;
        $totals = $this->computeTotalsFromItems($filters['split_merge_item'], $isTaxInc);
        $total_tax = $totals['total_tax'];
        $total_value = $totals['total_value'];

        // Use original - remain (rounding adjustment) if remain values available
        $hasRemain = isset($filters['_remain_vt']);
        if ($hasRemain) {
            $discountAmount = max(0, (float) ($originalInvoice->discount ?? 0) - (float) ($filters['_remain_disc'] ?? 0));
            if (($store->time_zone ?? null) === 'Asia/Manila') {
                $seniorAmount = max(0, (float) ($originalInvoice->senior_discount_amount ?? 0) - (float) ($filters['_remain_senior'] ?? 0));
                $isSenior = (bool) ($originalInvoice->is_senior_discount ?? false);
            } else {
                $seniorAmount = 0;
                $isSenior = false;
            }
            $isSeniorActive = $isSenior && $seniorAmount > 0;
            $surchargeAmount = max(0, (float) ($originalInvoice->surcharge ?? 0) - (float) ($filters['_remain_surcharge'] ?? 0));
            $serviceChargePercent = (float) ($filters['service_charge'] ?? ($store->service_charge ?? 0));
            $serviceChargeAmount = max(0, (float) ($originalInvoice->service_charge_amount ?? 0) - (float) ($filters['_remain_sc'] ?? 0));
            $total_tax = $isSeniorActive ? 0 : max(0, (float) ($originalInvoice->tax ?? 0) - (float) ($filters['_remain_tax'] ?? 0));
            $valuetotal = max(0, (float) ($originalInvoice->final_total ?? $originalInvoice->total ?? 0) - (float) ($filters['_remain_vt'] ?? 0));
        } else {
            // Fallback to independent calculation (no remain info)
            $typeDiscount = $filters['type_discount'] ?? 'amount';
            $discountPct = (float) ($filters['discount_percent'] ?? 0);
            $discountAmount = $typeDiscount === 'percent' ? $discountPct : (float) ($filters['discount'] ?? 0);
            $scBaseTotal = $total_value - $total_tax;
            if (($store->time_zone ?? null) === 'Asia/Manila') {
                $isSenior = (bool) ($filters['is_senior_discount'] ?? false);
                $seniorAmount = $isSenior ? (float) ($filters['senior_discount_amount'] ?? round($scBaseTotal * 20 / 100)) : 0;
            } else {
                $seniorAmount = 0;
                $isSenior = false;
            }
            $isSeniorActive = $isSenior && $seniorAmount > 0;
            $surchargePercent = (float) ($filters['surcharge_percent'] ?? 0);
            $surchargeAmount = (float) ($filters['surcharge'] ?? 0);
            if ($surchargeAmount == 0 && $surchargePercent > 0) {
                $surchargeAmount = round(($isSeniorActive ? max(0, $scBaseTotal - $seniorAmount - $discountAmount) : ($isTaxInc ? $total_value : $scBaseTotal) - ($isSeniorActive ? $seniorAmount : 0) - $discountAmount) * $surchargePercent / 100);
            }
            $serviceChargePercent = (float) ($filters['service_charge'] ?? ($store->service_charge ?? 0));
            $serviceChargeAmount = round(($isSeniorActive ? max(0, $scBaseTotal - $seniorAmount - $discountAmount) : ($isTaxInc ? $total_value : $scBaseTotal) - $discountAmount) * $serviceChargePercent / 100);
            if ($isSeniorActive) {
                $total_tax = 0;
            }
            $valuetotal = $isSeniorActive ? ($scBaseTotal - $seniorAmount - $discountAmount + $surchargeAmount + $serviceChargeAmount) : ($isTaxInc ? ($total_value - $discountAmount + $surchargeAmount + $serviceChargeAmount) : ($scBaseTotal - $discountAmount + $total_tax + $surchargeAmount + $serviceChargeAmount));
        }
        $surchargePercent = (float) ($filters['surcharge_percent'] ?? 0);
        if ($isSeniorActive) {
            $total_tax = 0;
        }

        $userId = $filters['user_id'] ?? ($originalInvoice ? $originalInvoice->user_id : 1);
        $paymentCode = 'EDGE-' . date('YmdHis') . '-' . random_int(1000, 9999);

        return [
            "reason" => $filters['reason'] ?? null,
            "customer_id" => $filters['customer_id'] ?? null,
            "items" => $items,
            "discount" => $discountAmount,
            "type_discount" => $typeDiscount,
            "discount_percent" => $typeDiscount === 'percent' ? (float) ($originalInvoice->discount_percent ?? 0) : null,
            "surcharge" => $surchargeAmount,
            "payment_method" => $filters['payment_method'] ?? 'cash',
            "status" => 0, // Pending/Unpaid
            "surcharge_reason" => $filters['surcharge_reason'] ?? null,
            "amount_received" => $filters['amount_received'] ?? null,
            "admin_id" => $filters['admin_id'] ?? 1,
            "user_id" => $userId,
            "payment_code" => $paymentCode,
            "store_id" => $filters['store_id'],
            "table_id" => $filters['target_table_id'],
            "valuetotal" => $valuetotal,
            "total_tax" => $total_tax,
            "is_senior_discount" => $isSenior,
            "senior_discount_amount" => $seniorAmount,
            "service_charge" => $serviceChargePercent,
            "service_charge_amount" => $serviceChargeAmount,
        ];
    }

    private function createPaymentLocal($data)
    {
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

        $productList = json_decode($payment->items, true);
        foreach ($productList['item'] as $key => $value) {
            $detailPrice = (float) ($value['price'] ?? 0);
            $detailQty = (int) ($value['quantity'] ?? 1);
            $detailTotal = (float) ($value['TotalPrice'] ?? ($value['total'] ?? ($detailPrice * $detailQty)));
            $detailVat = (float) ($value['vat'] ?? 0);
            $detailDiscountExcl = (float) ($value['detail_discount_excluding_tax'] ?? 0);
            $detailNetExcl = (float) ($value['discounted_price_excluding_tax'] ?? 0);
            $detailTaxAmt = (float) ($value['tax_amount'] ?? 0);
            // Compute missing fields if not provided
            $isSeniorActive = !empty($data['is_senior_discount']) && !empty($data['senior_discount_amount']);
            if ($isSeniorActive) {
                $detailTaxAmt = 0;
                $detailNetExcl = $detailTotal;
            } elseif ($detailNetExcl == 0 && $detailVat > 0) {
                $detailNetExcl = round($detailTotal / (1 + $detailVat / 100));
            } elseif ($detailNetExcl == 0) {
                $detailNetExcl = $detailTotal;
            }
            if (!$isSeniorActive && $detailTaxAmt == 0 && $detailVat > 0) {
                $detailTaxAmt = $detailTotal - $detailNetExcl;
            }
            PaymentDetail::create([
                'payment_id' => $payment->id,
                'product_id' => $value['id'],
                'product_key' => $key,
                'quantity' => $detailQty,
                'printed_quantity' => $value['printed_quantity'] ?? 0,
                'price' => $detailPrice,
                'total' => $detailTotal,
                'note' => $value['note'] ?? '',
                'product_extra' => !empty($value['extra_product_list']) ? json_encode($value['extra_product_list']) : null,
                'optional_products' => !empty($value['optional_products']) ? json_encode($value['optional_products']) : null,
                'detail_discount' => (float) ($value['detail_discount'] ?? 0),
                'detail_discount_excluding_tax' => $detailDiscountExcl,
                'discounted_price_excluding_tax' => $detailNetExcl,
                'tax_amount' => $detailTaxAmt,
                'unit_price_excluding_tax' => (float) ($value['unit_price_excluding_tax'] ?? round($detailNetExcl / $detailQty)),
                'admin_id' => $payment->admin_id,
                'store_id' => $payment->store_id,
            ]);
        }
        return ['status' => true, 'payment' => $payment];
    }

    private function updatePaymentLocal($data)
    {
        $payment = Payment::find($data['id']);
        if (!$payment) {
            throw new \RuntimeException('Payment not found: ' . $data['id']);
        }
        $payment->update([
            'items' => $data['items'],
            'total' => $data['valuetotal'],
            'discount' => $data['discount'] ?? $payment->discount,
            'surcharge' => $data['surcharge'] ?? $payment->surcharge,
            'surcharge_percent' => $data['surcharge_percent'] ?? $payment->surcharge_percent,
            'tax' => $data['total_tax'],
            'final_total' => $data['valuetotal'],
            'amount_received' => isset($data['amount_received']) ? round((float) $data['amount_received']) : $payment->amount_received,
            'is_senior_discount' => $data['is_senior_discount'] ?? $payment->is_senior_discount,
            'senior_discount_amount' => $data['senior_discount_amount'] ?? $payment->senior_discount_amount,
            'service_charge' => $data['service_charge'] ?? $payment->service_charge,
            'service_charge_amount' => $data['service_charge_amount'] ?? $payment->service_charge_amount,
        ]);

        $productList = json_decode($data['items'], true);
        $listPaymentDetail = PaymentDetail::where("payment_id", $data['id'])->pluck('product_key')->toArray();

        foreach ($productList['item'] as $key => $value) {
            $position = array_search((string)$key, $listPaymentDetail);
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
            PaymentDetail::updateOrCreate(
                [
                    'payment_id' => $data['id'],
                    'product_key' => $key,
                ],
                [
                    'product_id' => $value['id'],
                    'product_key' => $key,
                    'quantity' => $detailQty,
                    'printed_quantity' => $value['printed_quantity'] ?? 0,
                    'price' => $detailPrice,
                    'total' => $detailTotal,
                'note' => (function() use ($value) {
                    $parts = [];
                    if (!empty($value['note'])) $parts[] = $value['note'];
                    if (!empty($value['product_types']) && is_array($value['product_types'])) {
                        foreach ($value['product_types'] as $pt) {
                            if (!empty($pt['productTypeValue'])) $parts[] = $pt['productTypeValue'];
                        }
                    }
                    return !empty($parts) ? implode(', ', $parts) : null;
                })(),
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
        return $payment;
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
}
