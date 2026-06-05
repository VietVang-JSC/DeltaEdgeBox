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
                ->join('tables', 'payments.id', '=', 'tables.payment_id')
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

        $total_tax = 0;
        $total_value = 0;
        foreach ($filters['split_merge_item'] as $key => $value) {
            $total_tax += $this->calculateTotalAfterTax($value)['vatAmount'];
            $total_value += $this->calculateTotalAfterTax($value)['total'];
        }

        $discountAmount = (float) ($filters['discount'] ?? 0);
        $surchargeAmount = (float) ($filters['surcharge'] ?? 0);
        $store = Store::find($filters['store_id']);
        $serviceChargePercent = (float) ($filters['service_charge'] ?? ($store->service_charge ?? 0));
        $isSenior = $filters['is_senior_discount'] ?? ($originalInvoice->is_senior_discount ?? false);
        $seniorAmount = (float) ($filters['senior_discount_amount'] ?? ($originalInvoice->senior_discount_amount ?? 0));

        $baseForServiceCharge = $total_value - $discountAmount;
        if ($isSenior && $seniorAmount > 0) {
            $baseForServiceCharge = $total_value - $seniorAmount - $discountAmount;
        }
        $serviceChargeAmount = round($baseForServiceCharge * $serviceChargePercent / 100);

        $valuetotal = $total_value - $discountAmount + $surchargeAmount + $serviceChargeAmount;

        $paymentCode = 'EDGE-' . date('YmdHis') . '-' . random_int(1000, 9999);

        $paramCreatePayment = [
            "reason" => $filters['reason'] ?? null,
            "customer_id" => $filters['customer_id'] ?? null,
            "items" => $items,
            "discount" => $discountAmount,
            "type_discount" => $filters['type_discount'] ?? 'amount',
            "discount_percent" => $filters['discount_percent'] ?? null,
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
            "valuetotal" => $valuetotal,
            "total_tax" => $total_tax,
            "is_senior_discount" => $isSenior,
            "senior_discount_amount" => $seniorAmount,
            "service_charge" => $serviceChargePercent,
            "service_charge_amount" => $serviceChargeAmount,
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
                $quantity = $original_invoice[$key]['quantity'] - $split_merge_item[$key]['quantity'];
                if ($quantity <= 0) {
                    unset($original_invoice[$key]);
                } else {
                    $original_invoice[$key]['quantity'] = $quantity;
                    $TotalPrice = $is_tax_included ? intval($original_invoice[$key]['quantity']) * floatval($original_invoice[$key]['price']) : $this->calculateTotalAfterTax($original_invoice[$key])['total'];
                    $original_invoice[$key]['TotalPrice'] = $TotalPrice;
                }
                $TotalPrice = $is_tax_included ? intval($split_merge_item[$key]['quantity']) * floatval($split_merge_item[$key]['price']) : $this->calculateTotalAfterTax($split_merge_item[$key])['total'];
                $split_merge_item[$key]['TotalPrice'] = $TotalPrice;
            }
            return ['status' => true];
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    private function calculateTotalAfterTax(array $item): array
    {
        $quantity = intval($item['quantity']);
        $price = floatval($item['price']);
        $vatPercent = floatval($item['vat'] ?? 0);
        $subtotal = $quantity * $price;
        $vatAmount = round($subtotal * ($vatPercent / 100));
        $totalAfterTax = $subtotal + $vatAmount;
        return [
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => $subtotal,
            'vatPercent' => $vatPercent . '%',
            'vatAmount' => $vatAmount,
            'total' => $totalAfterTax,
        ];
    }

    private function handleUpdateOriginalInvoice($itemOriginalInvoice, $original_invoice)
    {
        $total_tax = 0;
        $total_value = 0;
        foreach ($itemOriginalInvoice['item'] as $key => $value) {
            $total_tax += $this->calculateTotalAfterTax($value)['vatAmount'];
            $total_value += $this->calculateTotalAfterTax($value)['total'];
        }
        $itemOriginalInvoice['total_tax'] = $total_tax;

        $discountAmount = (float) ($original_invoice->discount ?? 0);
        $surchargeAmount = (float) ($original_invoice->surcharge ?? 0);
        $baseForServiceCharge = $total_value - $discountAmount;
        $serviceChargePercent = (float) ($original_invoice->service_charge ?? 0);
        $serviceChargeAmount = round($baseForServiceCharge * $serviceChargePercent / 100);

        $seniorAmount = (float) ($original_invoice->senior_discount_amount ?? 0);
        if ($original_invoice->is_senior_discount && $seniorAmount > 0) {
            $baseForServiceCharge = $total_value - $seniorAmount - $discountAmount;
            $serviceChargeAmount = round($baseForServiceCharge * $serviceChargePercent / 100);
        }

        return [
            'id' => $original_invoice->id,
            'status' => $original_invoice->status,
            'valuetotal' => $total_value - $discountAmount + $surchargeAmount + $serviceChargeAmount,
            'items' => json_encode($itemOriginalInvoice),
            'total_tax' => $total_tax,
            'store_id' => $original_invoice->store_id,
            'admin_id' => $original_invoice->admin_id,
            'is_senior_discount' => $original_invoice->is_senior_discount ?? false,
            'senior_discount_amount' => $original_invoice->senior_discount_amount ?? 0,
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

        $total_tax = 0;
        $total_value = 0;
        foreach ($filters['split_merge_item'] as $key => $value) {
            $total_tax += $this->calculateTotalAfterTax($value)['vatAmount'];
            $total_value += $this->calculateTotalAfterTax($value)['total'];
        }

        $discountAmount = (float) ($filters['discount'] ?? 0);
        $surchargeAmount = (float) ($filters['surcharge'] ?? 0);
        $store = Store::find($filters['store_id']);
        $serviceChargePercent = (float) ($filters['service_charge'] ?? ($store->service_charge ?? 0));
        $typeDiscount = $filters['type_discount'] ?? 'amount';
        $isSenior = $filters['is_senior_discount'] ?? ($originalInvoice ? ($originalInvoice->is_senior_discount ?? false) : false);
        $seniorAmount = (float) ($filters['senior_discount_amount'] ?? ($originalInvoice ? ($originalInvoice->senior_discount_amount ?? 0) : 0));

        $baseForServiceCharge = $total_value - $discountAmount;
        if ($isSenior && $seniorAmount > 0) {
            $baseForServiceCharge = $total_value - $seniorAmount - $discountAmount;
        }
        $serviceChargeAmount = round($baseForServiceCharge * $serviceChargePercent / 100);

        $valuetotal = $total_value - $discountAmount + $surchargeAmount + $serviceChargeAmount;

        $userId = $filters['user_id'] ?? ($originalInvoice ? $originalInvoice->user_id : 1);
        $paymentCode = 'EDGE-' . date('YmdHis') . '-' . random_int(1000, 9999);

        return [
            "reason" => $filters['reason'] ?? null,
            "customer_id" => $filters['customer_id'] ?? null,
            "items" => $items,
            "discount" => $discountAmount,
            "type_discount" => $typeDiscount,
            "discount_percent" => $filters['discount_percent'] ?? null,
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
        $payment = Payment::create([
            'payment_code' => $data['payment_code'],
            'store_id' => $data['store_id'],
            'table_id' => $data['table_id'],
            'customer_id' => $data['customer_id'],
            'items' => $data['items'],
            'paid_date' => now(),
            'total' => $data['amount_received'] ?? $data['valuetotal'] ?? 0,
            'discount' => (float) ($data['discount'] ?? 0),
            'tax' => (float) ($data['total_tax'] ?? 0),
            'final_total' => $data['amount_received'] ?? $data['valuetotal'] ?? 0,
            'payment_method' => $data['payment_method'] ?? 'cash',
            'note' => $data['reason'] ?? null,
            'status' => $data['status'],
            'user_id' => $data['user_id'] ?? 1,
            'admin_id' => $data['admin_id'] ?? 1,
            'is_senior_discount' => $data['is_senior_discount'] ?? false,
            'senior_discount_amount' => $data['senior_discount_amount'] ?? 0,
            'service_charge' => $data['service_charge'] ?? 0,
            'service_charge_amount' => $data['service_charge_amount'] ?? 0,
        ]);

        $productList = json_decode($payment->items, true);
        foreach ($productList['item'] as $key => $value) {
            PaymentDetail::create([
                'payment_id' => $payment->id,
                'product_id' => $value['id'],
                'product_key' => $key,
                'quantity' => $value['quantity'],
                'price' => $value['price'],
                'total' => $value['TotalPrice'],
                'note' => $value['note'] ?? '',
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
            'tax' => $data['total_tax'],
            'final_total' => $data['valuetotal'],
            'is_senior_discount' => $data['is_senior_discount'] ?? $payment->is_senior_discount,
            'senior_discount_amount' => $data['senior_discount_amount'] ?? $payment->senior_discount_amount,
            'service_charge' => $data['service_charge'] ?? $payment->service_charge,
            'service_charge_amount' => $data['service_charge_amount'] ?? $payment->service_charge_amount,
        ]);

        $productList = json_decode($data['items'], true);
        $listPaymentDetail = PaymentDetail::where("payment_id", $data['id'])->pluck('product_id')->toArray();

        foreach ($productList['item'] as $key => $value) {
            $position = array_search((int)$value['id'], $listPaymentDetail);
            if ($position !== false) {
                unset($listPaymentDetail[$position]);
            }
            PaymentDetail::updateOrCreate(
                [
                    'payment_id' => $data['id'],
                    'product_id' => $value['id'],
                ],
                [
                    'quantity' => $value['quantity'],
                    'price' => $value['price'],
                    'total' => $value['TotalPrice'],
                    'note' => $value['note'] ?? '',
                    'admin_id' => $data['admin_id'] ?? 1,
                    'store_id' => $data['store_id'],
                ]
            );
        }
        if (!empty($listPaymentDetail)) {
            PaymentDetail::where('payment_id', $data['id'])->whereIn('product_id', array_values($listPaymentDetail))->delete();
        }
        return $payment;
    }

    private function handleData4TargetInvoice($filters, $targetInvoice)
    {
        $itemsOftargetInvoice = $this->getPaymentItems($targetInvoice);
        $item = $itemsOftargetInvoice['item'] ?? [];
        foreach ($filters['split_merge_item'] as $key => $value) {
            if (empty($item[$key])) {
                $item[$key] = $value;
            } else {
                $item[$key]['quantity'] += $value['quantity'];
            }
            $item[$key]['TotalPrice'] = $this->calculateTotalAfterTax($item[$key])['total'];
        }

        $total_tax = 0;
        $total_value = 0;
        foreach ($item as $key => $value) {
            $total_tax += $this->calculateTotalAfterTax($value)['vatAmount'];
            $total_value += $this->calculateTotalAfterTax($value)['total'];
        }

        $discountAmount = (float) ($targetInvoice['discount'] ?? 0);
        $surchargeAmount = (float) ($targetInvoice['surcharge'] ?? 0);
        $serviceChargePercent = (float) ($targetInvoice['service_charge'] ?? 0);
        $seniorAmount = (float) ($targetInvoice['senior_discount_amount'] ?? 0);

        $baseForServiceCharge = $total_value - $discountAmount;
        if (!empty($targetInvoice['is_senior_discount']) && $seniorAmount > 0) {
            $baseForServiceCharge = $total_value - $seniorAmount - $discountAmount;
        }
        $serviceChargeAmount = round($baseForServiceCharge * $serviceChargePercent / 100);

        $itemsOftargetInvoice['total_tax'] = $total_tax;
        $itemsOftargetInvoice['item'] = $item;
        return [
            'id' => $targetInvoice['id'],
            'status' => $targetInvoice['status'],
            'valuetotal' => $total_value - $discountAmount + $surchargeAmount + $serviceChargeAmount,
            'items' => json_encode($itemsOftargetInvoice),
            'total_tax' => $total_tax,
            'store_id' => $targetInvoice['store_id'],
            'admin_id' => $targetInvoice['admin_id'],
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

        foreach ($originalItems as $key => $value) {
            if (empty($targetItems[$key])) {
                $targetItems[$key] = $value;
            } else {
                $targetItems[$key]['quantity'] += $value['quantity'];
            }
            $targetItems[$key]['TotalPrice'] = $this->calculateTotalAfterTax($targetItems[$key])['total'];
        }

        $total_tax = 0;
        $total_value = 0;
        foreach ($targetItems as $key => $value) {
            $total_tax += $this->calculateTotalAfterTax($value)['vatAmount'];
            $total_value += $this->calculateTotalAfterTax($value)['total'];
        }

        $itemsTargetInvoice = $targetDecoded;
        $itemsTargetInvoice['total_tax'] = $total_tax;
        $itemsTargetInvoice['item'] = $targetItems;

        return [
            'id' => $target_invoice->id,
            'status' => $target_invoice->status,
            'valuetotal' => $total_value - $target_invoice->discount + $target_invoice->surcharge,
            'items' => json_encode($itemsTargetInvoice),
            'total_tax' => $total_tax,
            'store_id' => $target_invoice->store_id,
            'admin_id' => $target_invoice->admin_id,
            'is_senior_discount' => $target_invoice->is_senior_discount ?? false,
            'senior_discount_amount' => $target_invoice->senior_discount_amount ?? 0,
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
