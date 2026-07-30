<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Product;
use App\Models\Store;
use App\Models\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenPaymentMigrationService
{
    public function migrate(?int $storeId = null, bool $dryRun = false): array
    {
        $storeId = $storeId ?: (int) config('edge_box.store_id');
        [$cloudApiUrl, $apiKey] = $this->connectionForStore($storeId);

        $response = Http::withHeaders([
            'X-Edge-Api-Key' => $apiKey,
            'X-Store-API-Key' => $apiKey,
            'X-Store-ID' => $storeId,
            'Accept' => 'application/json',
        ])->timeout(60)->post(
            $cloudApiUrl . '/api/edge-cloud/open-payments',
            ['store_id' => $storeId]
        );

        if (!$response->successful()) {
            throw new RuntimeException(
                'Failed to fetch open payments from Cloud: HTTP '
                . $response->status() . ' ' . substr($response->body(), 0, 500)
            );
        }

        $payments = $response->json('data.payments', []);
        $resolvedPayments = $response->json('data.resolved_payments', []);
        if (!is_array($payments) || !is_array($resolvedPayments)) {
            throw new RuntimeException('Cloud returned an invalid open payments payload.');
        }

        $resolvable = $this->countResolvablePayments($resolvedPayments, $storeId);
        /*
        - cloud_open_payments: Tổng số đơn hàng đang mở (chưa thanh toán)
        - resolvable/resolved: Số lượng đơn hàng có thể/đã cập nhật trạng thái
         + là các đơn hàng ở edge đang chưa thanh toán or status !=0 thì cloud sẽ cập nhật lại 
        - imported: các hóa đươn mới hoàn toàn (edge chưa có)
        -linked: kiểm tra xem edge đã có hóa đơn đó chưa, có rồi thì liên kết id 
        -mapped_payments: Số lượng hóa đơn đã ánh xạ ID thành công giữa máy Edge và Cloud để gửi đăng ký mapping.
        -mapped_payment_details: Số lượng chi tiết món ăn (payment details) đã ánh xạ ID thành công giữa máy Edge và Cloud.
        */

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'store_id' => $storeId,
                'cloud_open_payments' => count($payments),
                'resolvable' => $resolvable,
                'resolved' => 0,
                'imported' => 0,
                'linked' => 0,
                'conflicts' => [],
                'mapped_payments' => 0,
                'mapped_payment_details' => 0,
            ];
        }

        $result = DB::transaction(function () use ($payments, $resolvedPayments, $storeId) {
            return Payment::withoutEvents(function () use ($payments, $resolvedPayments, $storeId) {
                return PaymentDetail::withoutEvents(function () use ($payments, $resolvedPayments, $storeId) {
                    $result = $this->importPayments($payments, $storeId);
                    $result['resolved'] = $this->resolveMappedPayments($resolvedPayments, $storeId);

                    return $result;
                });
            });
        });

        $mappingResponse = Http::withHeaders([
            'X-Edge-Api-Key' => $apiKey,
            'X-Store-API-Key' => $apiKey,
            'X-Store-ID' => $storeId,
            'Accept' => 'application/json',
        ])->timeout(60)->post(
            $cloudApiUrl . '/api/edge-cloud/open-payment-mappings',
            [
                'store_id' => $storeId,
                'payments' => $result['payment_mappings'],
                'payment_details' => $result['detail_mappings'],
            ]
        );

        if (!$mappingResponse->successful()) {
            Log::error('Open payment migration imported locally but mapping registration failed', [
                'store_id' => $storeId,
                'status' => $mappingResponse->status(),
                'body' => substr($mappingResponse->body(), 0, 500),
            ]);

            throw new RuntimeException(
                'Payments were imported locally, but Cloud mapping registration failed: HTTP '
                . $mappingResponse->status() . '. Re-run the migration safely to retry mapping.'
            );
        }

        return [
            'success' => true,
            'dry_run' => false,
            'store_id' => $storeId,
            'cloud_open_payments' => count($payments),
            'resolvable' => $resolvable,
            'resolved' => $result['resolved'],
            'imported' => $result['imported'],
            'linked' => $result['linked'],
            'conflicts' => $result['conflicts'],
            'mapped_payments' => count($result['payment_mappings']),
            'mapped_payment_details' => count($result['detail_mappings']),
        ];
    }

    protected function countResolvablePayments(array $resolvedPayments, int $storeId): int
    {
        $localIds = collect($resolvedPayments)
            ->filter(static fn ($payment) => (int) ($payment['status'] ?? 0) !== 0)
            ->pluck('local_id')
            ->filter(static fn ($id) => $id !== null && $id !== '')
            ->unique()
            ->values();

        if ($localIds->isEmpty()) {
            return 0;
        }

        return Payment::query()
            ->where('store_id', $storeId)
            ->where('status', 0)
            ->whereIn('id', $localIds)
            ->count();
    }

    protected function resolveMappedPayments(array $resolvedPayments, int $storeId): int
    {
        $resolved = 0;

        foreach ($resolvedPayments as $payment) {
            $localId = $payment['local_id'] ?? null;
            $status = (int) ($payment['status'] ?? 0);
            if ($localId === null || $localId === '' || $status === 0) {
                continue;
            }

            $attributes = ['status' => $status];
            if (!empty($payment['updated_at'])) {
                $attributes['updated_at'] = $payment['updated_at'];
            }

            $updatedCount = Payment::query()
                ->where('store_id', $storeId)
                ->whereKey($localId)
                ->where('status', 0)
                ->update($attributes);

            if ($updatedCount > 0 && ($status === 1 || $status === 2)) {
                // Free up local table when associated payment is paid/resolved on Cloud
                Table::query()
                    ->where('store_id', $storeId)
                    ->where('payment_id', $localId)
                    ->update([
                        'status' => 1,
                        'payment_id' => null,
                        'listitem' => null,
                        'userordered' => null,
                        'number_of_people' => 0,
                    ]);
            }

            $resolved += $updatedCount;
        }

        return $resolved;
    }

    protected function importPayments(array $cloudPayments, int $storeId): array
    {
        $result = [
            'imported' => 0,
            'linked' => 0,
            'conflicts' => [],
            'payment_mappings' => [],
            'detail_mappings' => [],
        ];
        $cloudToLocalPaymentIds = [];
        $pendingParents = [];
        $productIdsByCode = Product::query()
            ->where('store_id', $storeId)
            ->pluck('id', 'code')
            ->map(static fn ($id) => (int) $id)
            ->all();

        foreach ($cloudPayments as $cloudPayment) {
            if ((int) ($cloudPayment['store_id'] ?? 0) !== $storeId
                || (int) ($cloudPayment['status'] ?? 1) !== 0) {
                $result['conflicts'][] = [
                    'cloud_id' => $cloudPayment['id'] ?? null,
                    'reason' => 'Payment does not belong to the requested store or is not open.',
                ];
                continue;
            }

            $cloudPaymentId = (int) $cloudPayment['id'];
            $paymentCode = trim((string) ($cloudPayment['payment_code'] ?? ''));
            $lookupCode = $paymentCode !== '' ? $paymentCode : 'CLOUD-' . $cloudPaymentId;
            $localPayment = Payment::query()
                ->where('store_id', $storeId)
                ->where('payment_code', $lookupCode)
                ->first();

            if (!$localPayment) {
                $sameId = Payment::withTrashed()->find($cloudPaymentId);
                $attributes = $this->paymentAttributes($cloudPayment, $storeId, $lookupCode);
                $localPayment = new Payment($attributes);

                if (!$sameId) {
                    $localPayment->id = $cloudPaymentId;
                }

                $localPayment->save();
                $result['imported']++;
                $isImported = true;
            } else {
                $result['linked']++;
                $isImported = false;
            }

            $cloudToLocalPaymentIds[$cloudPaymentId] = (int) $localPayment->id;
            $pendingParents[(int) $localPayment->id] = $cloudPayment['parent_id'] ?? null;
            $result['payment_mappings'][] = [
                'local_id' => (string) $localPayment->id,
                'cloud_id' => $cloudPaymentId,
            ];

            $cloudDetails = $cloudPayment['payment_details'] ?? $cloudPayment['paymentDetails'] ?? [];
            foreach ($cloudDetails as $cloudDetail) {
                $cloudDetailId = (int) ($cloudDetail['id'] ?? 0);
                if (!$cloudDetailId) {
                    continue;
                }

                $productCode = $this->productCode($cloudDetail);
                $localProductId = $productCode !== ''
                    ? ($productIdsByCode[$productCode] ?? null)
                    : null;

                if (!$localProductId && !empty($cloudDetail['product_id'])) {
                    $candidateProduct = Product::query()
                        ->where('store_id', $storeId)
                        ->find((int) $cloudDetail['product_id']);
                    $localProductId = $candidateProduct?->id;
                }

                if (!$localProductId) {
                    $result['conflicts'][] = [
                        'cloud_id' => $cloudPaymentId,
                        'cloud_detail_id' => $cloudDetailId,
                        'reason' => 'Product not found on Edge: ' . ($productCode ?: $cloudDetail['product_id']),
                    ];
                    continue;
                }

                $localDetail = $isImported
                    ? null
                    : $this->findExistingDetail($localPayment, $cloudDetail, (int) $localProductId);

                if (!$localDetail && !$isImported) {
                    $result['conflicts'][] = [
                        'cloud_id' => $cloudPaymentId,
                        'cloud_detail_id' => $cloudDetailId,
                        'reason' => 'Existing local payment detail does not match Cloud; local data was preserved.',
                    ];
                    continue;
                }

                if (!$localDetail) {
                    $localDetail = new PaymentDetail($this->detailAttributes(
                        $cloudDetail,
                        (int) $localPayment->id,
                        (int) $localProductId,
                        $storeId
                    ));

                    if (!PaymentDetail::withTrashed()->whereKey($cloudDetailId)->exists()) {
                        $localDetail->id = $cloudDetailId;
                    }

                    $localDetail->save();
                }

                $result['detail_mappings'][] = [
                    'local_id' => (string) $localDetail->id,
                    'cloud_id' => $cloudDetailId,
                ];
            }
        }

        foreach ($pendingParents as $localPaymentId => $cloudParentId) {
            if (!$cloudParentId || empty($cloudToLocalPaymentIds[(int) $cloudParentId])) {
                continue;
            }

            Payment::query()->whereKey($localPaymentId)->update([
                'parent_id' => $cloudToLocalPaymentIds[(int) $cloudParentId],
            ]);
        }

        return $result;
    }

    protected function findExistingDetail(Payment $payment, array $cloudDetail, int $productId): ?PaymentDetail
    {
        $query = $payment->details()->where('product_id', $productId);
        $productKey = trim((string) ($cloudDetail['product_key'] ?? ''));

        if ($productKey !== '') {
            $match = (clone $query)->where('product_key', $productKey)->first();
            if ($match) {
                return $match;
            }
        }

        return $query
            ->where('quantity', (int) ($cloudDetail['quantity'] ?? 1))
            ->where('price', (float) ($cloudDetail['price'] ?? 0))
            ->first();
    }

    protected function paymentAttributes(array $payment, int $storeId, string $paymentCode): array
    {
        return [
            'payment_code' => $paymentCode,
            'parent_id' => null,
            'store_id' => $storeId,
            'table_id' => $payment['table_id'] ?? null,
            'customer_id' => $payment['customer_id'] ?? 0,
            'reason' => $payment['reason'] ?? '',
            'items' => $payment['items'] ?? '',
            'paid_date' => $payment['paid_date'] ?? $payment['created_at'] ?? now(),
            'total' => $payment['valuetotal'] ?? $payment['total'] ?? 0,
            'discount' => $payment['discount'] ?? 0,
            'surcharge' => $payment['surcharge'] ?? 0,
            'surcharge_reason' => $payment['surcharge_reason'] ?? '',
            'surcharge_percent' => $payment['surcharge_percent'] ?? 0,
            'service_charge' => $payment['service_charge'] ?? 0,
            'service_charge_amount' => $payment['service_charge_amount'] ?? 0,
            'tax' => $payment['total_tax'] ?? $payment['tax'] ?? 0,
            'final_total' => $payment['valuetotal'] ?? $payment['final_total'] ?? 0,
            'amount_received' => $payment['amount_received'] ?? null,
            'payment_method' => $payment['payment_method'] ?? 1,
            'note' => $payment['reason'] ?? '',
            'status' => 0,
            'is_printed' => $payment['is_printed'] ?? false,
            'invoice_sent_status' => $payment['invoice_sent_status'] ?? false,
            'user_id' => $payment['user_id'] ?? 1,
            'admin_id' => $payment['admin_id'] ?? 1,
            'financial_id' => $payment['financial_id'] ?? null,
            'number_of_people' => $payment['number_of_people'] ?? 0,
            'type_discount' => $payment['type_discount'] ?? 'amount',
            'discount_percent' => $payment['discount_percent'] ?? 0,
            'is_senior_discount' => $payment['is_senior_discount'] ?? false,
            'senior_discount_amount' => $payment['senior_discount_amount'] ?? 0,
            'payment_transaction_id' => $payment['payment_transaction_id'] ?? null,
            'sub_total_before_discount' => $payment['sub_total_before_discount'] ?? 0,
            'total_incl_vat_before_discount' => $payment['total_incl_vat_before_discount'] ?? 0,
            'created_at' => $payment['created_at'] ?? now(),
            'updated_at' => $payment['updated_at'] ?? now(),
        ];
    }

    protected function detailAttributes(array $detail, int $paymentId, int $productId, int $storeId): array
    {
        return [
            'payment_id' => $paymentId,
            'product_id' => $productId,
            'product_key' => $detail['product_key'] ?? $this->productCode($detail),
            'quantity' => $detail['quantity'] ?? 1,
            'price' => $detail['price'] ?? 0,
            'total' => $detail['total_price'] ?? $detail['total'] ?? 0,
            'note' => $detail['note'] ?? '',
            'product_extra' => $detail['product_extra'] ?? null,
            'optional_products' => $detail['optional_products'] ?? null,
            'inventory_histories' => $detail['inventory_histories'] ?? null,
            'input_code' => $detail['input_code'] ?? null,
            'admin_id' => $detail['admin_id'] ?? 1,
            'store_id' => $storeId,
            'debt' => $detail['debt'] ?? 0,
            'status' => $detail['status'] ?? null,
            'delete_note' => $detail['delete_note'] ?? null,
            'detail_discount' => $detail['detail_discount'] ?? 0,
            'served' => $detail['served'] ?? false,
            'tax_amount' => $detail['tax_amount'] ?? 0,
            'detail_discount_excluding_tax' => $detail['detail_discount_excluding_tax'] ?? 0,
            'unit_price_excluding_tax' => $detail['unit_price_excluding_tax'] ?? 0,
            'discounted_price_excluding_tax' => $detail['discounted_price_excluding_tax'] ?? 0,
            'printed_quantity' => $detail['printed_quantity'] ?? 0,
            'created_at' => $detail['created_at'] ?? now(),
            'updated_at' => $detail['updated_at'] ?? now(),
        ];
    }

    protected function productCode(array $detail): string
    {
        $product = $detail['products'] ?? $detail['product'] ?? [];
        $code = $detail['product_code'] ?? ($product['product_code'] ?? '');

        if (!$code && !empty($detail['product_key'])) {
            $code = explode('.', (string) $detail['product_key'])[0] ?? '';
        }

        return trim((string) $code);
    }

    protected function connectionForStore(int $storeId): array
    {
        $cloudApiUrl = rtrim((string) config('edge_box.cloud_api_url'), '/');
        $store = Store::query()->find($storeId);
        $apiKey = (string) ($store?->api_key ?: config('edge_box.api_key'));

        if ($storeId <= 0 || $cloudApiUrl === '' || $apiKey === '') {
            throw new RuntimeException('Cloud URL, store ID, or store API key is not configured.');
        }

        return [$cloudApiUrl, $apiKey];
    }
}
