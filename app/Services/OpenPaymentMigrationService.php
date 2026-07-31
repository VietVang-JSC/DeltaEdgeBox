<?php

namespace App\Services;

use App\Models\OpenPaymentMappingOutbox;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Product;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
            $cloudApiUrl.'/api/edge-cloud/open-payments',
            [
                'store_id' => $storeId,
                'local_open_payments' => $this->localOpenCandidates($storeId),
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                'Failed to fetch open payments from Cloud: HTTP '
                .$response->status().' '.substr($response->body(), 0, 500)
            );
        }

        $payments = $response->json('data.payments', []);
        $resolvedPayments = $response->json('data.resolved_payments', []);
        $resolutionConflicts = $response->json('data.resolution_conflicts', []);
        if (! is_array($payments) || ! is_array($resolvedPayments) || ! is_array($resolutionConflicts)) {
            throw new RuntimeException('Cloud returned an invalid open payments payload.');
        }

        $resolutionPreview = $this->previewResolutions($resolvedPayments, $storeId);
        /*
        - cloud_open_payments: Tổng số đơn hàng đang mở (chưa thanh toán)
        - resolvable/resolved: Số lượng đơn hàng có thể/đã cập nhật trạng thái
         + là các đơn hàng ở edge đang chưa thanh toán or status !=0 thì cloud sẽ cập nhật lại
        - resolvable_by_mapping: Số lượng đơn hàng có thể được xác định bằng mapping (local_id)
        - resolvable_by_payment_code: Số lượng đơn hàng có thể được xác định bằng mã hóa đơn
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
                'resolvable' => $resolutionPreview['resolvable'],
                'resolvable_by_mapping' => $resolutionPreview['by_mapping'],
                'resolvable_by_payment_code' => $resolutionPreview['by_payment_code'],
                'resolved' => 0,
                'imported' => 0,
                'linked' => 0,
                'conflicts' => array_merge($resolutionConflicts, $resolutionPreview['conflicts']),
                'mapped_payments' => 0,
                'mapped_payment_details' => 0,
            ];
        }

        $result = DB::transaction(function () use ($payments, $resolvedPayments, $storeId) {
            return Payment::withoutEvents(function () use ($payments, $resolvedPayments, $storeId) {
                return PaymentDetail::withoutEvents(function () use ($payments, $resolvedPayments, $storeId) {
                    $result = $this->importPayments($payments, $storeId);
                    $resolution = $this->resolveMappedPayments($resolvedPayments, $storeId);
                    $result['resolved'] = $resolution['resolved'];
                    $result['repaired'] = $resolution['repaired'];
                    $result['released'] = $resolution['released'];
                    $result['conflicts'] = array_merge($result['conflicts'], $resolution['conflicts']);
                    $this->queueMappings($storeId, 'payments', $result['payment_mappings']);
                    $this->queueMappings($storeId, 'payment_details', $result['detail_mappings']);

                    return $result;
                });
            });
        });

        $mappingResult = $this->flushPendingMappings($storeId, $cloudApiUrl, $apiKey);

        return [
            'success' => true,
            'dry_run' => false,
            'store_id' => $storeId,
            'cloud_open_payments' => count($payments),
            'resolvable' => $resolutionPreview['resolvable'],
            'resolvable_by_mapping' => $resolutionPreview['by_mapping'],
            'resolvable_by_payment_code' => $resolutionPreview['by_payment_code'],
            'resolved' => $result['resolved'],
            'repaired' => $result['repaired'],
            'released' => $result['released'],
            'imported' => $result['imported'],
            'linked' => $result['linked'],
            'conflicts' => array_merge($resolutionConflicts, $result['conflicts']),
            'mapped_payments' => count($result['payment_mappings']),
            'mapped_payment_details' => count($result['detail_mappings']),
            'mapping_sent' => $mappingResult['sent'],
            'mapping_pending' => $mappingResult['pending'],
        ];
    }

    public function reconcile(?int $storeId = null, bool $dryRun = false): array
    {
        $storeId = $storeId ?: (int) config('edge_box.store_id');
        [$cloudApiUrl, $apiKey] = $this->connectionForStore($storeId);
        $response = Http::withHeaders([
            'X-Edge-Api-Key' => $apiKey,
            'X-Store-API-Key' => $apiKey,
            'X-Store-ID' => $storeId,
            'Accept' => 'application/json',
        ])->timeout(60)->post($cloudApiUrl.'/api/edge-cloud/open-payments', [
            'store_id' => $storeId,
            'local_open_payments' => $this->localOpenCandidates($storeId),
            'reconcile_only' => true,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to reconcile payments from Cloud: HTTP '.$response->status());
        }

        $resolvedPayments = $response->json('data.resolved_payments', []);
        $cloudConflicts = $response->json('data.resolution_conflicts', []);
        $preview = $this->previewResolutions($resolvedPayments, $storeId);

        if ($dryRun) {
            return array_merge($preview, [
                'success' => true,
                'dry_run' => true,
                'store_id' => $storeId,
                'resolved' => 0,
                'repaired' => 0,
                'released' => 0,
                'conflicts' => array_merge($cloudConflicts, $preview['conflicts']),
            ]);
        }

        $result = DB::transaction(fn () => Payment::withoutEvents(
            fn () => $this->resolveMappedPayments($resolvedPayments, $storeId)
        ));
        $mappingResult = $this->flushPendingMappings($storeId, $cloudApiUrl, $apiKey);

        return array_merge($preview, $result, [
            'success' => true,
            'dry_run' => false,
            'store_id' => $storeId,
            'conflicts' => array_merge($cloudConflicts, $result['conflicts']),
            'mapping_sent' => $mappingResult['sent'],
            'mapping_pending' => $mappingResult['pending'],
        ]);
    }

    protected function previewResolutions(array $resolvedPayments, int $storeId): array
    {
        $preview = [
            'resolvable' => 0,
            'by_mapping' => 0,
            'by_payment_code' => 0,
            'conflicts' => [],
        ];

        foreach ($resolvedPayments as $payment) {
            $status = (int) ($payment['status'] ?? 0);
            if ($status === 0) {
                continue;
            }

            $resolution = $this->resolveLocalPayment($payment, $storeId);
            if (! $resolution['payment']) {
                $preview['conflicts'][] = $resolution['conflict'];

                continue;
            }

            $preview['resolvable']++;
            $preview[$resolution['source'] === 'mapping' ? 'by_mapping' : 'by_payment_code']++;
        }

        return $preview;
    }

    protected function resolveMappedPayments(array $resolvedPayments, int $storeId): array
    {
        $result = [
            'resolved' => 0,
            'repaired' => 0,
            'released' => 0,
            'conflicts' => [],
        ];

        foreach ($resolvedPayments as $cloudPayment) {
            $status = (int) ($cloudPayment['status'] ?? 0);
            if ($status === 0) {
                continue;
            }

            $resolution = $this->resolveLocalPayment($cloudPayment, $storeId);
            $payment = $resolution['payment'];
            if (! $payment) {
                $result['conflicts'][] = $resolution['conflict'];
                Log::warning('OPEN_PAYMENT_RESOLUTION_CONFLICT', $resolution['conflict']);

                continue;
            }

            if ($resolution['source'] === 'payment_code_unique' && ! empty($cloudPayment['cloud_id'])) {
                $this->queueMappings($storeId, 'payments', [[
                    'local_id' => (string) $payment->id,
                    'cloud_id' => (int) $cloudPayment['cloud_id'],
                ]]);
            }

            $wasOpen = (int) $payment->status === 0;
            if ($wasOpen) {
                $payment->status = $status;
                if (! empty($cloudPayment['updated_at'])) {
                    $payment->updated_at = $this->normalizeSqliteDateTime($cloudPayment['updated_at']);
                }
                $payment->save();
                $result['resolved']++;
            } else {
                $result['repaired']++;
            }

            $released = app(TableReleaseService::class)->releaseForPayment($payment, $storeId);
            $result['released'] += $released;

            Log::info('OPEN_PAYMENT_RESOLVED', [
                'store_id' => $storeId,
                'local_payment_id' => $payment->id,
                'cloud_payment_id' => $cloudPayment['cloud_id'] ?? null,
                'payment_code' => $payment->payment_code,
                'resolution_source' => $resolution['source'],
                'table_released' => $released > 0,
            ]);
        }

        return $result;
    }

    protected function resolveLocalPayment(array $cloudPayment, int $storeId): array
    {
        $localId = $cloudPayment['local_id'] ?? null;
        if ($localId !== null && $localId !== '') {
            $payment = Payment::query()
                ->where('store_id', $storeId)
                ->whereKey($localId)
                ->first();
            if ($payment) {
                return ['payment' => $payment, 'source' => 'mapping', 'conflict' => null];
            }
        }

        $paymentCode = trim((string) ($cloudPayment['payment_code'] ?? ''));
        if ($paymentCode === '') {
            return [
                'payment' => null,
                'source' => null,
                'conflict' => [
                    'reason' => 'local_payment_not_found',
                    'store_id' => $storeId,
                    'local_id' => $localId,
                    'cloud_id' => $cloudPayment['cloud_id'] ?? null,
                ],
            ];
        }

        $matches = Payment::query()
            ->where('store_id', $storeId)
            ->where('payment_code', $paymentCode)
            ->limit(2)
            ->get();

        if ($matches->count() === 1) {
            return ['payment' => $matches->first(), 'source' => 'payment_code_unique', 'conflict' => null];
        }

        return [
            'payment' => null,
            'source' => null,
            'conflict' => [
                'reason' => $matches->isEmpty()
                    ? 'local_payment_not_found'
                    : 'ambiguous_local_payment_code',
                'store_id' => $storeId,
                'local_id' => $localId,
                'cloud_id' => $cloudPayment['cloud_id'] ?? null,
                'payment_code' => $paymentCode,
                'candidate_local_ids' => $matches->pluck('id')->all(),
            ],
        ];
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
            $lookupCode = $paymentCode !== '' ? $paymentCode : 'CLOUD-'.$cloudPaymentId;
            $localPayment = Payment::query()
                ->where('store_id', $storeId)
                ->where('payment_code', $lookupCode)
                ->first();

            if (! $localPayment) {
                $sameId = Payment::withTrashed()->find($cloudPaymentId);
                $attributes = $this->paymentAttributes($cloudPayment, $storeId, $lookupCode);
                $localPayment = new Payment($attributes);

                if (! $sameId) {
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
            $claimedLocalDetailIds = [];
            foreach ($cloudDetails as $cloudDetail) {
                $cloudDetailId = (int) ($cloudDetail['id'] ?? 0);
                if (! $cloudDetailId) {
                    continue;
                }

                $productCode = $this->productCode($cloudDetail);
                $localProductId = $productCode !== ''
                    ? ($productIdsByCode[$productCode] ?? null)
                    : null;

                if (! $localProductId && ! empty($cloudDetail['product_id'])) {
                    $candidateProduct = Product::query()
                        ->where('store_id', $storeId)
                        ->find((int) $cloudDetail['product_id']);
                    $localProductId = $candidateProduct?->id;
                }

                if (! $localProductId) {
                    $result['conflicts'][] = [
                        'cloud_id' => $cloudPaymentId,
                        'cloud_detail_id' => $cloudDetailId,
                        'reason' => 'Product not found on Edge: '.($productCode ?: $cloudDetail['product_id']),
                    ];

                    continue;
                }

                $localDetail = $isImported
                    ? null
                    : $this->findExistingDetail(
                        $localPayment,
                        $cloudDetail,
                        (int) $localProductId,
                        $claimedLocalDetailIds
                    );

                if (! $localDetail && ! $isImported) {
                    $result['conflicts'][] = [
                        'cloud_id' => $cloudPaymentId,
                        'cloud_detail_id' => $cloudDetailId,
                        'reason' => 'Existing local payment detail does not match Cloud; local data was preserved.',
                    ];

                    continue;
                }

                if (! $localDetail) {
                    $localDetail = new PaymentDetail($this->detailAttributes(
                        $cloudDetail,
                        (int) $localPayment->id,
                        (int) $localProductId,
                        $storeId
                    ));

                    if (! PaymentDetail::withTrashed()->whereKey($cloudDetailId)->exists()) {
                        $localDetail->id = $cloudDetailId;
                    }

                    $localDetail->save();
                }

                $claimedLocalDetailIds[] = (int) $localDetail->id;

                $result['detail_mappings'][] = [
                    'local_id' => (string) $localDetail->id,
                    'cloud_id' => $cloudDetailId,
                ];
            }
        }

        foreach ($pendingParents as $localPaymentId => $cloudParentId) {
            if (! $cloudParentId || empty($cloudToLocalPaymentIds[(int) $cloudParentId])) {
                continue;
            }

            Payment::query()->whereKey($localPaymentId)->update([
                'parent_id' => $cloudToLocalPaymentIds[(int) $cloudParentId],
            ]);
        }

        return $result;
    }

    protected function findExistingDetail(
        Payment $payment,
        array $cloudDetail,
        int $productId,
        array $claimedLocalDetailIds = []
    ): ?PaymentDetail {
        $query = $payment->details()
            ->where('product_id', $productId)
            ->when($claimedLocalDetailIds !== [], fn ($builder) => $builder->whereNotIn('id', $claimedLocalDetailIds));
        $productKey = trim((string) ($cloudDetail['product_key'] ?? ''));

        if ($productKey !== '') {
            $matches = (clone $query)->where('product_key', $productKey)->limit(2)->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        $matches = $query
            ->where('quantity', (int) ($cloudDetail['quantity'] ?? 1))
            ->where('price', (float) ($cloudDetail['price'] ?? 0))
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
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
            'paid_date' => $this->normalizeSqliteDateTime($payment['paid_date'] ?? $payment['created_at'] ?? now()),
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
            'created_at' => $this->normalizeSqliteDateTime($payment['created_at'] ?? now()),
            'updated_at' => $this->normalizeSqliteDateTime($payment['updated_at'] ?? now()),
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
            'created_at' => $this->normalizeSqliteDateTime($detail['created_at'] ?? now()),
            'updated_at' => $this->normalizeSqliteDateTime($detail['updated_at'] ?? now()),
        ];
    }

    protected function productCode(array $detail): string
    {
        $product = $detail['products'] ?? $detail['product'] ?? [];
        $code = $detail['product_code'] ?? ($product['product_code'] ?? '');

        if (! $code && ! empty($detail['product_key'])) {
            $code = explode('.', (string) $detail['product_key'])[0] ?? '';
        }

        return trim((string) $code);
    }

    protected function localOpenCandidates(int $storeId): array
    {
        return Payment::query()
            ->where('store_id', $storeId)
            ->where('status', 0)
            ->whereNotNull('payment_code')
            ->where('payment_code', '!=', '')
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'payment_code'])
            ->map(static fn (Payment $payment) => [
                'local_id' => (string) $payment->id,
                'payment_code' => $payment->payment_code,
            ])
            ->all();
    }

    protected function queueMappings(int $storeId, string $sourceTable, array $mappings): void
    {
        foreach ($mappings as $mapping) {
            OpenPaymentMappingOutbox::query()->updateOrCreate(
                [
                    'store_id' => $storeId,
                    'source_table' => $sourceTable,
                    'local_id' => (string) $mapping['local_id'],
                ],
                [
                    'cloud_id' => (int) $mapping['cloud_id'],
                    'status' => 'pending',
                    'last_error' => null,
                ]
            );

            Log::info('OPEN_PAYMENT_MAPPING_QUEUED', [
                'store_id' => $storeId,
                'source_table' => $sourceTable,
                'local_id' => (string) $mapping['local_id'],
                'cloud_id' => (int) $mapping['cloud_id'],
            ]);
        }
    }

    protected function flushPendingMappings(int $storeId, string $cloudApiUrl, string $apiKey): array
    {
        $pending = OpenPaymentMappingOutbox::query()
            ->where('store_id', $storeId)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($pending->isEmpty()) {
            return ['sent' => 0, 'pending' => 0];
        }

        $payload = [
            'store_id' => $storeId,
            'payments' => $pending->where('source_table', 'payments')->map(fn ($row) => [
                'local_id' => $row->local_id,
                'cloud_id' => $row->cloud_id,
            ])->values()->all(),
            'payment_details' => $pending->where('source_table', 'payment_details')->map(fn ($row) => [
                'local_id' => $row->local_id,
                'cloud_id' => $row->cloud_id,
            ])->values()->all(),
        ];

        try {
            $response = Http::withHeaders([
                'X-Edge-Api-Key' => $apiKey,
                'X-Store-API-Key' => $apiKey,
                'X-Store-ID' => $storeId,
                'Accept' => 'application/json',
            ])->timeout(60)->post($cloudApiUrl.'/api/edge-cloud/open-payment-mappings', $payload);

            if (! $response->successful()) {
                throw new RuntimeException('HTTP '.$response->status().' '.substr($response->body(), 0, 500));
            }

            OpenPaymentMappingOutbox::query()
                ->whereIn('id', $pending->pluck('id'))
                ->update(['status' => 'sent', 'last_error' => null]);

            return ['sent' => $pending->count(), 'pending' => 0];
        } catch (Throwable $e) {
            OpenPaymentMappingOutbox::query()
                ->whereIn('id', $pending->pluck('id'))
                ->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => substr($e->getMessage(), 0, 2000),
                ]);

            Log::warning('OPEN_PAYMENT_MAPPING_RETRY_FAILED', [
                'store_id' => $storeId,
                'pending_ids' => $pending->pluck('id')->all(),
                'error' => $e->getMessage(),
            ]);

            return ['sent' => 0, 'pending' => $pending->count()];
        }
    }

    private function normalizeSqliteDateTime($value): string
    {
        return Carbon::parse($value)
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
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
