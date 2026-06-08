<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentPrintController extends Controller
{
    public function printPayment(Request $request)
    {
        $paymentId = $request->input('payment_id');
        if (!$paymentId) {
            return $this->error('payment_id is required', 400);
        }

        $payment = Payment::with(['table', 'user', 'store', 'details.product'])->find($paymentId);
        if (!$payment) {
            return $this->error('Payment not found', 404);
        }

        return response()->json([
            'status' => true,
            'status_code' => 200,
            'message' => 'Get info success',
            'data' => [
                'data_payment' => $this->paymentPayload($payment),
                'data_bill_setting' => [],
                'data_bank_payment' => [],
            ],
        ]);
    }

    public function printForWeb(Request $request)
    {
        return $this->printPayment($request);
    }

    private function paymentPayload(Payment $payment): array
    {
        $payload = $payment->toArray();
        $details = $payment->getRelation('details');
        $table = $payment->relationLoaded('table')
            ? $payment->getRelation('table')
            : $payment->table()->first();
        $payload['valuetotal'] = $payload['total'] ?? 0;
        $payload['total_tax'] = $payload['tax'] ?? 0;
        $payload['amount_received'] = $payload['final_total'] ?? 0;
        $payload['sub_total_before_discount'] = $payload['sub_total_before_discount'] ?? ($payload['total'] ?? 0);
        $payload['total_incl_vat_before_discount'] = $payload['total_incl_vat_before_discount'] ?? ($payload['total'] ?? 0);
        $payload['is_senior_discount'] = $payload['is_senior_discount'] ?? false;
        $payload['senior_discount_amount'] = $payload['senior_discount_amount'] ?? 0;
        $payload['service_charge'] = $payload['service_charge'] ?? 0;
        $payload['service_charge_amount'] = $payload['service_charge_amount'] ?? 0;
        $payload['surcharge'] = $payload['surcharge'] ?? 0;
        $payload['surcharge_percent'] = $payload['surcharge_percent'] ?? 0;
        $payload['type_discount'] = $payload['type_discount'] ?? 'amount';
        $payload['discount_percent'] = $payload['discount_percent'] ?? 0;
        $payload['table'] = $table ? $table->toArray() : null;
        $payload['user'] = $payment->user ? $payment->user->toArray() : null;
        $payload['store'] = $payment->store ? $payment->store->toArray() : null;
        $payload['payment_details'] = $details ? $details->map(function ($detail) {
            $detailPayload = $detail->toArray();
            $product = $detail->relationLoaded('product')
                ? $detail->getRelation('product')
                : $detail->product()->first();
            $productPayload = $product ? $product->toArray() : [];

            $detailPayload['total_price'] = $detailPayload['total_price'] ?? ($detailPayload['total'] ?? 0);
            $detailPayload['products'] = [
                'id' => $productPayload['id'] ?? $detail->product_id,
                'title' => $productPayload['title'] ?? ($productPayload['name'] ?? ''),
                'name' => $productPayload['name'] ?? ($productPayload['title'] ?? ''),
                'vat' => $productPayload['vat'] ?? 0,
                'code' => $productPayload['code'] ?? null,
            ];

            return $detailPayload;
        })->values()->all() : [];

        if (empty($payload['items']) && $details) {
            $payload['items'] = json_encode([
                'item' => $details->map(function ($detail) {
                    $product = $detail->getRelation('product');

                    return [
                        'id' => $detail->product_id,
                        'product_id' => $detail->product_id,
                        'title' => is_object($product) ? ($product->name ?? null) : null,
                        'quantity' => $detail->quantity,
                        'price' => $detail->price,
                        'TotalPrice' => $detail->total,
                        'total' => $detail->total,
                        'note' => $detail->note,
                    ];
                })->values()->all(),
                'discountPayment' => $payment->discount ?? 0,
                'surcharge' => $payment->surcharge ?? 0,
            ]);
        }

        return $payload;
    }

    private function error($message, int $statusCode)
    {
        return response()->json([
            'status' => false,
            'status_code' => $statusCode,
            'message' => $message,
        ], $statusCode);
    }
}
