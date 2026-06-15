<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        $paymentId = $request->input('payment_id');
        if (!$paymentId) {
            return response()->json(['status' => false, 'message' => 'payment_id is required'], 400);
        }

        $payment = Payment::with(['table', 'user', 'store', 'details.product'])->find($paymentId);
        if (!$payment) {
            return response()->json(['status' => false, 'message' => 'Payment not found'], 404);
        }

        $store = $payment->store;
        $language = $request->input('language', 'vi');
        app()->setLocale($language);

        // Use paymentPayload() for 100% consistent format with cloud API
        $paymentData = $this->paymentPayload($payment);
        $paymentData['created_at'] = date('d-m-Y H:i:s', strtotime($payment->created_at));
        $paymentData['updated_at'] = date('d-m-Y H:i:s', strtotime($payment->updated_at));

        // Generate QR image if needed (use blank for now)
        $qrImagePath = '';

        $paperSize = $request->input('paper_size', '80');
        $tpl = 'invoice.template_invoice_' . $language . '_' . $paperSize;
        if (!view()->exists($tpl)) {
            $tpl = 'invoice.template_invoice_vi_80';
        }

        try {
            $html = view($tpl, [
                'payment' => $paymentData,
                'bill_setting' => [],
                'data_bank_payment' => [],
                'is_tax_included' => $store ? ($store->is_tax_included ?? false) : false,
                'qrImagePath' => $qrImagePath,
            ])->render();
        } catch (\Throwable $th) {
            Log::error('Edge print template render failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Print render failed'], 500);
        }

        return response()->json([
            'status' => true,
            'data' => $html,
        ]);
    }

    private function paymentPayload(Payment $payment): array
    {
        $payload = $payment->toArray();
        $details = $payment->getRelation('details');
        $table = $payment->relationLoaded('table')
            ? $payment->getRelation('table')
            : $payment->table()->first();
        $payload['valuetotal'] = $payload['final_total'] ?? 0;
        $payload['total_tax'] = $payload['tax'] ?? 0;
        $payload['amount_received'] = $payload['amount_received'] ?? ($payload['final_total'] ?? 0);
        $payload['sub_total_before_discount'] = $payload['sub_total_before_discount'] ?? ($payload['total'] ?? 0);
        $payload['total_incl_vat_before_discount'] = $payload['total_incl_vat_before_discount'] ?? ($payload['total'] ?? 0);
        $payload['payment_code'] = $payload['payment_code'] ?: ('EDGE-' . $payment->id);
        $payload['is_senior_discount'] = $payload['is_senior_discount'] ?? false;
        $payload['senior_discount_amount'] = $payload['senior_discount_amount'] ?? 0;
        $payload['service_charge'] = $payload['service_charge'] ?? 0;
        $payload['service_charge_amount'] = $payload['service_charge_amount'] ?? 0;
        $payload['surcharge'] = $payload['surcharge'] ?? 0;
        $payload['surcharge_percent'] = $payload['surcharge_percent'] ?? 0;
        $payload['type_discount'] = $payload['type_discount'] ?? 'amount';
        $payload['discount_percent'] = $payload['discount_percent'] ?? 0;
        $payload['table'] = $table ? $table->toArray() : null;
        $user = $payment->user;
        if (!$user && !empty($payment->admin_id)) {
            $user = User::find($payment->admin_id);
        }
        if (!$user && !empty($payment->user_id)) {
            $user = User::find($payment->user_id);
        }
        $payload['user'] = $user ? $user->toArray() : [
            'id' => $payment->user_id,
            'name' => '',
        ];
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
