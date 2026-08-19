<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Models\Printer;
use App\Models\Table;
use App\Services\PaymentDetailCanonicalizer;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $language = $request->input('language', $request->input('isCheckLanguage', 'vi'));
        app()->setLocale($language);
        $timeZone = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');

        // Use paymentPayload() for 100% consistent format with cloud API
        $paymentData = $this->paymentPayload($payment);
        if ($payment->created_at) {
            $paymentData['created_at'] = $payment->created_at->setTimezone($timeZone)->format('d-m-Y H:i:s');
        }
        if ($payment->updated_at) {
            $paymentData['updated_at'] = $payment->updated_at->setTimezone($timeZone)->format('d-m-Y H:i:s');
        }

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
                'timeZone' => $timeZone,
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
        $details = app(PaymentDetailCanonicalizer::class)->canonicalize(
            $payment,
            $payment->getRelation('details')
        );
        $table = $payment->relationLoaded('table')
            ? $payment->getRelation('table')
            : $payment->table()->first();
        $payload['valuetotal'] = $payload['final_total'] ?? 0;
        $payload['total_tax'] = $payload['tax'] ?? 0;
        $payload['amount_received'] = $payload['amount_received'] ?? ($payload['final_total'] ?? 0);
        // Calculate subtotal from payment_details for accuracy
        $subtotalFromDetails = $details ? array_sum(array_map(fn($d) => (float) ($d['total_price'] ?? $d['total'] ?? 0), $details->toArray())) : 0;
        $payload['sub_total_before_discount'] = !empty($payload['sub_total_before_discount']) ? (float) $payload['sub_total_before_discount'] : ($subtotalFromDetails ?: ($payload['total'] ?? 0));
        $payload['total_incl_vat_before_discount'] = !empty($payload['total_incl_vat_before_discount']) ? (float) $payload['total_incl_vat_before_discount'] : ($subtotalFromDetails ?: ($payload['total'] ?? 0));
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
        if (!$user) {
            $user = User::where('store_id', $payment->store_id)->first();
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

    public function printPaymentPdf(Request $request)
    {
        $paymentId = $request->input('payment_id');
        if (!$paymentId) {
            return $this->error('payment_id is required', 400);
        }

        $payment = Payment::with(['table', 'user', 'store', 'details.product'])->find($paymentId);
        if (!$payment) {
            return $this->error('Payment not found', 404);
        }

        $store = $payment->store;
        $storeId = $store ? $store->id : 1;
        $language = $request->input('language', $request->input('isCheckLanguage', 'vi'));
        app()->setLocale($language);

        // Fetch default receipt printer details
        $defaultPrinter = Printer::where('store_id', $storeId)
            ->where('printer_type', 'receipt')
            ->where('default', 1)
            ->first();

        $paperSize = $defaultPrinter ? $defaultPrinter->paper_size : 80;

        // Use paymentPayload() for 100% consistent format with cloud API
        $paymentData = $this->paymentPayload($payment);
        $timeZone = $store ? $store->time_zone : null;
        if (!$timeZone || $timeZone === 'UTC') {
            $timeZone = config('app.timezone');
        }
        if ($payment->created_at) {
            $paymentData['created_at'] = $payment->created_at->setTimezone($timeZone)->format('d-m-Y H:i:s');
        }
        if ($payment->updated_at) {
            $paymentData['updated_at'] = $payment->updated_at->setTimezone($timeZone)->format('d-m-Y H:i:s');
        }

        $qrImagePath = '';

        $tpl = 'invoice.template_invoice_' . $language . '_' . $paperSize;
        if (!view()->exists($tpl)) {
            $tpl = 'invoice.template_invoice_vi_80';
        }

        $billSetting = [];
        if ($store) {
            $billSetting = [
                'store_name' => $store->storename ?: $store->name,
                'store_address' => $store->address,
                'store_phone' => $store->phone,
                'logo' => '',
            ];
        }

        try {
            ini_set('memory_limit', '256M'); // Allocate memory dynamically for long bills
            $params = [
                'payment' => $paymentData,
                'bill_setting' => $billSetting,
                'data_bank_payment' => [],
                'is_tax_included' => $store ? ($store->is_tax_included ?? false) : false,
                'qrImagePath' => $qrImagePath,
                'timeZone' => $timeZone,
            ];

            // Localized Japanese date formatting to match Cloud BE
            if ($language === 'jp') {
                $timestamp = strtotime($paymentData['created_at']);
                if ($timestamp !== false) {
                    $date = new \DateTime();
                    $date->setTimestamp($timestamp);
                    $params['payment']['created_at'] = $date->format('Y年n月j日');
                }
            }

            // Generate receipt PDF bytes locally
            $pdfContent = $this->generateReceiptPDF($params, $storeId, $tpl, $language, $paperSize);
            $base64Pdf = base64_encode($pdfContent);

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'In hóa đơn thành công',
                'data' => [
                    'ip_address' => $defaultPrinter ? $defaultPrinter->ip_address : '',
                    'printer_url' => $store ? $store->printer_host : '',
                    'data' => $base64Pdf,
                    'printer_type' => $defaultPrinter ? $defaultPrinter->printer_type : 'receipt',
                    'paper_size' => $paperSize,
                ],
            ]);

        } catch (\Throwable $th) {
            Log::error('Edge printPaymentPdf failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Print rendering or PDF generation failed: ' . $th->getMessage()], 500);
        }
    }

    public function printTemporarySplitPayment(Request $request)
    {
        $acceptFields = [
            'split_merge_item',
            'original_invoice_id',
            'discount',
            'surcharge',
            'type_discount',
            'discount_percent',
            'amount_received',
            'surcharge_percent',
            'service_charge',
            'language',
            'is_senior_discount',
            'senior_discount_amount',
        ];

        $filters = $request->only($acceptFields);
        $validator = \Validator::make($filters, [
            'split_merge_item' => ['required'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        $paymentId = $filters['original_invoice_id'] ?? null;
        $payment = null;
        if ($paymentId) {
            $payment = Payment::with(['table', 'user', 'store'])->find($paymentId);
        }
        if (!$payment) {
            \Illuminate\Support\Facades\Log::warning('Edge temp split bill: payment not resolved', [
                'original_invoice_id' => $filters['original_invoice_id'] ?? null,
            ]);
        }

        // Get Store configuration
        $storeId = config('edge_box.store_id', 1);
        $store = Store::find($storeId);
        $isTaxIncluded = $store ? ($store->is_tax_included ?? false) : false;
        $timeZone = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');

        // Re-allocate discounts/taxes among split items using local buildSimplePayment
        $itemsInput = $filters['split_merge_item'];
        if (is_string($itemsInput)) {
            $itemsInput = json_decode($itemsInput, true);
        }
        $filters['split_merge_item'] = $itemsInput['item'] ?? $itemsInput ?? [];

        $temporaryPayment = $this->buildSimplePayment($filters, $isTaxIncluded);
        $temporaryPayment['amount_received'] = 0; 
        
        $table = null;
        if ($payment && $payment->table) {
            $table = $payment->table;
        } elseif ($payment && !empty($payment->table_id)) {
            $table = Table::find($payment->table_id);
        }
        $temporaryPayment['table'] = $table ? $table->toArray() : [];

        $user = $payment && $payment->user ? $payment->user : null;
        if (!$user && $payment && !empty($payment->admin_id)) {
            $user = User::find($payment->admin_id);
        }
        if (!$user && $payment && !empty($payment->user_id)) {
            $user = User::find($payment->user_id);
        }
        if (!$user) {
            $user = User::where('store_id', $storeId)->first();
        }
        $temporaryPayment['user'] = $user ? $user->toArray() : [
            'id' => $payment ? $payment->user_id : null,
            'name' => '',
        ];

        // Format times and payment code to match Cloud
        $temporaryPayment['created_at'] = now($timeZone)->format('d-m-Y H:i:s');
        $temporaryPayment['updated_at'] = now($timeZone)->format('d-m-Y H:i:s');
        $temporaryPayment['payment_code'] = $payment ? ($payment->payment_code ?: 'EDGE-' . $payment->id) : 'EDGE-TEMP';

        // Fetch printer details
        $defaultPrinter = Printer::where('store_id', $storeId)
            ->where('printer_type', 'receipt')
            ->where('default', 1)
            ->first();

        $paperSize = $defaultPrinter ? $defaultPrinter->paper_size : 80;

        $language = $request->input('language', $request->input('isCheckLanguage', 'vi'));
        app()->setLocale($language);

        $tpl = 'invoice.template_invoice_' . $language . '_' . $paperSize;
        if (!view()->exists($tpl)) {
            $tpl = 'invoice.template_invoice_vi_80';
        }

        $billSetting = [];
        if ($store) {
            $billSetting = [
                'store_name' => $store->storename ?: $store->name,
                'store_address' => $store->address,
                'store_phone' => $store->phone,
                'logo' => '',
            ];
        }

        $qrImagePath = '';

        try {
            ini_set('memory_limit', '256M'); // Allocate memory dynamically for long bills
            $params = [
                'payment' => $temporaryPayment,
                'bill_setting' => $billSetting,
                'data_bank_payment' => [],
                'is_tax_included' => $isTaxIncluded,
                'qrImagePath' => $qrImagePath,
                'timeZone' => $timeZone,
            ];

            // Localized Japanese date formatting to match Cloud BE
            if ($language === 'jp') {
                $timestamp = strtotime($temporaryPayment['created_at']);
                if ($timestamp !== false) {
                    $date = new \DateTime();
                    $date->setTimestamp($timestamp);
                    $params['payment']['created_at'] = $date->format('Y年n月j日');
                }
            }

            // Generate receipt PDF bytes locally
            $pdfContent = $this->generateReceiptPDF($params, $storeId, $tpl, $language, $paperSize);
            $base64Pdf = base64_encode($pdfContent);

            // Mark split items as printed in local DB so check-payment-printed returns them as done (white background)
            if ($payment) {
                try {
                    $detailKeys = [];
                    foreach ($filters['split_merge_item'] as $k => $it) {
                        $key = is_array($it) ? ($it['product_key'] ?? $it['key'] ?? $k) : $k;
                        if (!empty($key)) {
                            $detailKeys[] = $key;
                        }
                    }
                    if (!empty($detailKeys)) {
                        $affected = 0;
                        $details = \App\Models\PaymentDetail::where('payment_id', $payment->id)
                            ->whereIn('product_key', $detailKeys)
                            ->whereNull('deleted_at')
                            ->get();
                        if ($details->isEmpty()) {
                            $availableKeys = \App\Models\PaymentDetail::where('payment_id', $payment->id)
                                ->whereNull('deleted_at')
                                ->pluck('product_key')
                                ->all();
                            \Illuminate\Support\Facades\Log::warning('Edge temp split bill mark printed: no matching details', ['payment_id' => $payment->id, 'requested_keys' => $detailKeys, 'available_keys' => $availableKeys]);
                        }
                        foreach ($details as $detail) {
                            if ($detail->printed_quantity < $detail->quantity) {
                                $detail->printed_quantity = $detail->quantity;
                                if ($detail->save()) {
                                    $affected++;
                                }
                            }
                        }
                        \Illuminate\Support\Facades\Log::info('Edge temp split bill marked printed', ['payment_id' => $payment->id, 'keys' => $detailKeys, 'affected' => $affected]);
                    }
                } catch (\Throwable $markTh) {
                    \Illuminate\Support\Facades\Log::error('Edge temp split bill mark printed FAILED', ['payment_id' => $payment->id, 'error' => $markTh->getMessage()]);
                }

                // Sync printed/served state back into table.listitem so the app's item objects stay consistent
                try {
                    $this->syncPrintedStateToTableListItem($table, $payment);
                } catch (\Throwable $syncTh) {
                    \Illuminate\Support\Facades\Log::error('Edge temp split bill sync listitem FAILED', ['payment_id' => $payment->id, 'error' => $syncTh->getMessage()]);
                }
            }

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'In hóa đơn thành công',
                'data' => [
                    'ip_address' => $defaultPrinter ? $defaultPrinter->ip_address : '',
                    'printer_url' => $store ? $store->printer_host : '',
                    'data' => $base64Pdf,
                    'printer_type' => $defaultPrinter ? $defaultPrinter->printer_type : 'receipt',
                    'paper_size' => $paperSize,
                ],
            ]);

        } catch (\Throwable $th) {
            Log::error('Edge printTemporarySplitPayment failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Print rendering or PDF generation failed: ' . $th->getMessage()], 500);
        }
    }

    private function generateReceiptPDF($params, $storeId, $templatePath, $language = 'vi', $paperSize = 80)
    {
        try {
            $heightExtra = 0;
            $contentWidth = $paperSize == 80 ? 227 : 164;
            $maxTries = 50;
            $tryCount = 0;
            $pdf = null;

            do {
                $pdf = Pdf::loadView($templatePath, $params);
                $contentHeight = $this->calculateContentHeight($params, $language, $heightExtra);
                $pdf->setPaper([0, 0, $contentWidth, $contentHeight]);
                $pdf->render();

                $pageCount = $pdf->getDomPDF()->getCanvas()->get_page_count();
                $heightExtra += 150;
                $tryCount++;
            } while ($pageCount > 1 && $tryCount < $maxTries);

            return $pdf->output();
        } catch (\Throwable $th) {
            Log::error('generateReceiptPDF failed', ['error' => $th->getMessage()]);
            throw $th;
        }
    }

    private function calculateContentHeight($params, $language = 'vi', $heightExtra = 0)
    {
        try {
            $baseHeight = 350;
            if ($language == 'jp') {
                if (!empty($params['payment']['table'])) {
                    $baseHeight += 70;
                }
            }
            $itemHeight = 30;
            if (empty($params['data_bank_payment'])) {
                $baseHeight += 100;
            }

            $details = $params['payment']['payment_details'] ?? [];
            $countProduct = count($details);

            foreach ($details as $key => $value) {
                if (!empty($value['product_extra'])) {
                    $extra = is_string($value['product_extra']) ? json_decode($value['product_extra'], true) : $value['product_extra'];
                    if (is_array($extra)) {
                        $countProduct += count($extra) - 1;
                    }
                }
            }
            $baseHeight += $heightExtra;
            $totalHeight = $baseHeight + ($countProduct * $itemHeight);
            return $totalHeight;
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function buildSimplePayment(array $attributes, $isTaxIncluded = 0): array
    {
        $paymentDetails = [];
        $originalOrder = array_keys($attributes['split_merge_item'] ?? []);

        uasort($attributes['split_merge_item'], function ($a, $b) {
            return ($b['vat'] ?? 0) <=> ($a['vat'] ?? 0);
        });

        if (!isset($attributes['discount'])) {
            $attributes['discount'] = 0;
        }

        $totalDiscount = $attributes['discount'] ?? 0;
        $typeDiscount = $attributes['type_discount'] ?? 'amount';
        if ($typeDiscount == 'percent') {
            $totalDiscount = $attributes['discount_percent'] ?? 0;
        } else {
            $attributes['discount_percent'] = null;
        }

        $billItem = $isTaxIncluded
            ? $this->allocateDiscountTaxIncluded($attributes['split_merge_item'], $totalDiscount, $typeDiscount)
            : $this->allocateDiscountTaxExcluded($attributes['split_merge_item'], $totalDiscount, $typeDiscount);

        $attributes['split_merge_item'] = array_replace(array_flip($originalOrder), $billItem['items']);

        foreach ($attributes['split_merge_item'] as $key => $value) {
            $noteParts = [];
            if (!empty($value['note']))
                $noteParts[] = $value['note'];
            if (!empty($value['product_types']) && is_array($value['product_types'])) {
                foreach ($value['product_types'] as $pt) {
                    if (!empty($pt['productTypeValue']))
                        $noteParts[] = $pt['productTypeValue'];
                }
            }
            $paymentDetails[] = [
                'quantity' => $value['quantity'],
                'price' => $value['price'],
                'total_price' => $value['TotalPrice'] ?? $value['total'] ?? 0,
                'note' => !empty($noteParts) ? implode(', ', $noteParts) : null,
                'product_extra' => !empty($value['extra_product_list']) ? json_encode($value['extra_product_list']) : null,
                'products' => [
                    'title' => $value['title'] ?? '',
                    'vat' => $value['vat'] ?? 0,
                ],
                'tax_amount' => $value['tax_amount'] ?? 0,
            ];
        }

        $params = [
            'payment_details' => $paymentDetails,
            'surcharge' => $attributes['surcharge'] ?? 0,
            'discount' => $billItem['summary']['discount_total'],
            'total_tax' => $billItem['summary']['total_vat'],
            'valuetotal' => $billItem['summary']['total_with_vat'] + ($attributes['surcharge'] ?? 0),
            'amount_received' => $attributes['amount_received'] ?? ($billItem['summary']['total_with_vat'] + ($attributes['surcharge'] ?? 0)),
            'status' => 0,
            'sub_total_before_discount' => round($billItem['summary']['subtotal_before']),
            'total_incl_vat_before_discount' => $billItem['summary']['total_incl_vat_before_discount'],
        ];

        // Surcharge percent and service charge calculations for Asia/Manila store branch
        $storeId = config('edge_box.store_id', 1);
        $store = Store::find($storeId);
        if ($store && $store->time_zone == 'Asia/Manila') {
            $serviceCharge = isset($attributes['service_charge']) ? (int) $attributes['service_charge'] : ($store->service_charge ?? 0);
            $params['service_charge'] = $serviceCharge;

            $baseForSurcharge = $isTaxIncluded
                ? $billItem['summary']['total_with_vat']
                : ($billItem['summary']['subtotal_after'] ?? $billItem['summary']['total_with_vat']);

            // surcharge percent
            if (!empty($attributes['surcharge_percent'])) {
                $params['surcharge_percent'] = $attributes['surcharge_percent'];
                $params['surcharge'] = $baseForSurcharge * $attributes['surcharge_percent'] / 100;
            }

            $params['service_charge_amount'] = round($baseForSurcharge * $serviceCharge / 100);
            $params['valuetotal'] = $billItem['summary']['total_with_vat'] + $params['service_charge_amount'] + ($params['surcharge'] ?? 0);
            $params['amount_received'] = $params['valuetotal'];

            // Senior Discount (RA 9994)
            if (!empty($attributes['is_senior_discount'])) {
                $params['is_senior_discount'] = true;
                $seniorRate = 20; // Default 20%
                $seniorDiscountAmount = round($billItem['summary']['subtotal_before'] * $seniorRate / 100);
                $params['senior_discount_amount'] = $seniorDiscountAmount;

                $afterSenior = $billItem['summary']['subtotal_before'] - $seniorDiscountAmount;

                if ($typeDiscount === 'percent') {
                    $billItem['summary']['discount_total'] = round($afterSenior * $totalDiscount / 100);
                }
                $params['discount'] = $billItem['summary']['discount_total'];

                $totalAfterDiscount = $afterSenior - $billItem['summary']['discount_total'];
                $params['total_tax'] = 0; // VAT exempt

                $basePositive = max(0, $totalAfterDiscount);
                $params['service_charge_amount'] = round($basePositive * $serviceCharge / 100);

                if (!empty($attributes['surcharge_percent'])) {
                    $params['surcharge'] = $basePositive * $attributes['surcharge_percent'] / 100;
                }

                $params['valuetotal'] = $basePositive + $params['service_charge_amount'] + ($params['surcharge'] ?? 0);
                $params['amount_received'] = $params['valuetotal'];
            }
        }

        return $params;
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
            $item['detail_discount_excluding_tax'] = $item['discount_allocated_excl_vat'];
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

    private function syncPrintedStateToTableListItem(?Table $table, ?Payment $payment): void
    {
        if (!$table || !$payment || !$table->listitem) {
            return;
        }

        $decoded = json_decode($table->listitem, true) ?: [];
        $rawItems = $decoded['item'] ?? $decoded ?? [];
        $details = $payment->details()->whereNull('deleted_at')->get();

        foreach ($details as $detail) {
            foreach ($rawItems as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $productId = $item['product_id'] ?? $item['id'] ?? null;
                $matchesKey = !empty($detail->product_key) && (string) $key === (string) $detail->product_key;
                $matchesProduct = (string) $productId === (string) $detail->product_id;

                if (!$matchesKey && !$matchesProduct) {
                    continue;
                }

                $rawItems[$key]['printed_quantity'] = (int) $detail->printed_quantity;
                $rawItems[$key]['diff_quantity'] = max((int) $detail->quantity - (int) $detail->printed_quantity, 0);
                $rawItems[$key]['print_status'] = (int) $detail->printed_quantity >= (int) $detail->quantity;
                $rawItems[$key]['served'] = (bool) $detail->served;
            }
        }

        if (isset($decoded['item'])) {
            $decoded['item'] = $rawItems;
            $table->listitem = json_encode($decoded);
        } else {
            $table->listitem = json_encode($rawItems);
        }

        $table->save();
    }
}
