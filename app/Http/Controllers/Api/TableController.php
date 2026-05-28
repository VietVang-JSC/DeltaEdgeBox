<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Table;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TableController extends Controller
{
    private const STATUS_BUSY = 0;
    private const STATUS_ACTIVE = 1;
    private const STATUS_ORDERED = 2;
    private const PAYMENT_PENDING = 0;
    private const PAYMENT_CANCELLED = 2;

    public function index(Request $request)
    {
        $storeId = $this->storeId($request);
        $tables = Table::query()
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->where('status', '!=', -1)
            ->orderBy('id')
            ->get()
            ->map(fn (Table $table) => $this->tablePayload($table));

        return $this->success('api.table_get_list', ['table' => $tables]);
    }

    public function show(Request $request, $id = null)
    {
        $tableId = $id ?: $request->input('id');
        $table = $this->findTable($tableId, $request);
        if (!$table) {
            return $this->error('api.table_empty', 404);
        }

        return $this->success('api.table_get', ['table' => $this->tablePayload($table)]);
    }

    public function checkIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), 400);
        }

        try {
            $table = $this->findTable($request->input('id'), $request);
            if (!$table) {
                return $this->error('api.table_empty', 404);
            }

            $table->fill([
                'status' => self::STATUS_BUSY,
                'user_id' => $this->userId($request),
            ])->save();

            $this->processSyncAfterResponse();

            return $this->success('api.table_update_status', [
                'table' => $this->tablePayload($table->fresh()),
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge table check-in failed', ['error' => $th->getMessage()]);

            return $this->error('api.ISError', 500);
        }
    }

    public function checkOut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), 400);
        }

        try {
            $table = DB::transaction(function () use ($request) {
                $table = $this->findTable($request->input('id'), $request, true);
                if (!$table) {
                    return null;
                }

                $paymentId = $request->input('payment_id', $table->payment_id);
                if ($paymentId) {
                    Payment::whereKey($paymentId)->update([
                        'status' => self::PAYMENT_CANCELLED,
                        'note' => $request->input('reason'),
                    ]);
                }

                $table->fill($this->clearTablePayload())->save();

                return $table->fresh();
            });

            if (!$table) {
                return $this->error('api.table_empty', 404);
            }

            $this->processSyncAfterResponse();

            return $this->success('api.table_update_status', [
                'table' => $this->tablePayload($table),
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge table check-out failed', ['error' => $th->getMessage()]);

            return $this->error('api.ISError', 500);
        }
    }

    public function updateOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer', 'min:1'],
            'listitem' => ['required'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), 400);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                $table = $this->findTable($request->input('id'), $request, true);
                if (!$table) {
                    return null;
                }

                $listitem = $this->normalizeListItem($request->input('listitem'));
                $items = $this->decodeItems($listitem);
                $summary = $this->summarizeItems($items, $request);
                $payment = $this->upsertPendingPayment($request, $table, $items, $summary);

                $table->fill([
                    'status' => self::STATUS_ORDERED,
                    'user_id' => $this->userId($request),
                    'listitem' => $listitem,
                    'payment_id' => $payment->id,
                    'number_of_people' => (int) $request->input('number_of_people', $table->number_of_people ?? 0),
                ])->save();

                return [
                    'table' => $table->fresh(),
                    'payment' => $payment->fresh('details'),
                ];
            });

            if (!$result) {
                return $this->error('api.table_empty', 404);
            }

            $this->processSyncAfterResponse();

            return $this->success('Cap nhat mon thanh cong', [
                'data' => [
                    'table' => $this->tablePayload($result['table']),
                    'payment' => $this->paymentPayload($result['payment']),
                ],
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge table update order failed', ['error' => $th->getMessage()]);

            return $this->error('api.ISError', 500);
        }
    }

    public function changeTable(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'old_id_table' => ['required', 'integer', 'min:1'],
            'new_id_table' => ['required', 'integer', 'min:1', 'different:old_id_table'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), 400);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                $oldTable = $this->findTable($request->input('old_id_table'), $request, true);
                $newTable = $this->findTable($request->input('new_id_table'), $request, true);
                if (!$oldTable || !$newTable) {
                    return null;
                }

                $oldListItem = $request->filled('listitem_old')
                    ? $this->normalizeListItem($request->input('listitem_old'))
                    : null;
                $newListItem = $request->filled('listitem_new')
                    ? $this->normalizeListItem($request->input('listitem_new'))
                    : null;

                if ($oldListItem !== null || $newListItem !== null) {
                    $oldTable->fill([
                        'listitem' => $oldListItem,
                        'status' => $oldListItem ? self::STATUS_ORDERED : self::STATUS_ACTIVE,
                    ])->save();

                    $newTable->fill([
                        'listitem' => $newListItem,
                        'status' => $newListItem ? self::STATUS_ORDERED : self::STATUS_ACTIVE,
                        'user_id' => $this->userId($request),
                    ])->save();
                } else {
                    $newTable->fill([
                        'status' => $oldTable->status,
                        'user_id' => $oldTable->user_id,
                        'payment_id' => $oldTable->payment_id,
                        'listitem' => $oldTable->listitem,
                        'number_of_people' => $oldTable->number_of_people,
                    ])->save();

                    if ($oldTable->payment_id) {
                        Payment::whereKey($oldTable->payment_id)->update([
                            'table_id' => $newTable->id,
                        ]);
                    }

                    $oldTable->fill($this->clearTablePayload())->save();
                }

                return [
                    'old_table' => $oldTable->fresh(),
                    'new_table' => $newTable->fresh(),
                ];
            });

            if (!$result) {
                return $this->error('api.table_empty', 404);
            }

            $this->processSyncAfterResponse();

            return $this->success('api.table_update_status', [
                'data' => [
                    'old_table' => $this->tablePayload($result['old_table']),
                    'new_table' => $this->tablePayload($result['new_table']),
                ],
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge table change failed', ['error' => $th->getMessage()]);

            return $this->error('api.ISError', 500);
        }
    }

    private function upsertPendingPayment(Request $request, Table $table, array $items, array $summary): Payment
    {
        $payment = $request->filled('payment_id')
            ? Payment::find($request->input('payment_id'))
            : ($table->payment_id ? Payment::find($table->payment_id) : null);

        $payload = [
            'store_id' => $this->storeId($request),
            'table_id' => $table->id,
            'customer_id' => $request->input('customer_id') ?: null,
            'paid_date' => now(),
            'total' => $summary['total'],
            'discount' => (float) $request->input('discount', 0),
            'tax' => (float) $request->input('total_tax', 0),
            'final_total' => (float) $request->input('valuetotal', $summary['total']),
            'payment_method' => (string) $request->input('payment_method', 'cash'),
            'note' => $request->input('reason'),
            'status' => (int) $request->input('status', self::PAYMENT_PENDING),
            'user_id' => $this->userId($request),
        ];

        if ($payment) {
            $payment->update($payload);
        } else {
            $payment = Payment::create($payload);
        }

        $printedQuantities = $payment->details()
            ->whereNull('deleted_at')
            ->get()
            ->mapWithKeys(function ($detail) {
                $key = $detail->product_key ?: 'product:' . $detail->product_id;

                return [$key => (int) $detail->printed_quantity];
            });

        $payment->details()->delete();
        foreach ($items as $item) {
            $detailKey = $item['product_key'] ?: 'product:' . $item['product_id'];
            $printedQuantity = min((int) ($printedQuantities[$detailKey] ?? 0), (int) $item['quantity']);

            PaymentDetail::create([
                'payment_id' => $payment->id,
                'product_id' => $item['product_id'],
                'product_key' => $item['product_key'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $item['total'],
                'note' => $item['note'] ?? null,
                'product_extra' => $item['product_extra'] ?? null,
                'optional_products' => $item['optional_products'] ?? null,
                'printed_quantity' => $printedQuantity,
            ]);
        }

        return $payment->load('details');
    }

    private function findTable($id, Request $request, bool $lock = false): ?Table
    {
        $query = Table::query()->whereKey($id);
        $storeId = $this->storeId($request);
        if ($storeId) {
            $query->where('store_id', $storeId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function normalizeListItem($listitem): string
    {
        if (is_string($listitem)) {
            return $listitem;
        }

        return json_encode($listitem);
    }

    private function decodeItems(string $listitem): array
    {
        $decoded = json_decode($listitem, true) ?: [];
        $rawItems = $decoded['item'] ?? $decoded ?? [];

        $items = [];
        foreach ($rawItems as $productKey => $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $item['product_id'] ?? $item['id'] ?? null;
            if (!$productId) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            $total = (float) ($item['TotalPrice'] ?? $item['total'] ?? ($price * $quantity));

            $items[] = [
                'product_id' => (int) $productId,
                'product_key' => is_string($productKey) ? $productKey : null,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $total,
                'note' => $item['note'] ?? $item['noted'] ?? null,
                'product_extra' => !empty($item['extra_product_list']) ? json_encode($item['extra_product_list']) : null,
                'optional_products' => !empty($item['optional_products']) ? json_encode($item['optional_products']) : null,
            ];
        }

        return $items;
    }

    private function summarizeItems(array $items, Request $request): array
    {
        $total = (float) $request->input('valuetotal', 0);
        if ($total <= 0) {
            $total = array_sum(array_map(fn ($item) => (float) $item['total'], $items));
        }

        return ['total' => $total];
    }

    private function tablePayload(Table $table): array
    {
        $payload = $table->toArray();
        $payload['tablename'] = $payload['tablename'] ?? $payload['name'] ?? null;

        if ($table->relationLoaded('payment') && $table->payment) {
            $payload['payment'] = $this->paymentPayload($table->payment);
        } elseif ($table->payment_id) {
            $payment = Payment::with('details')->find($table->payment_id);
            $payload['payment'] = $payment ? $this->paymentPayload($payment) : null;
        }

        return $payload;
    }

    private function paymentPayload(Payment $payment): array
    {
        $payload = $payment->loadMissing('details')->toArray();
        $payload['valuetotal'] = $payload['total'] ?? 0;
        $payload['total_tax'] = $payload['tax'] ?? 0;
        $payload['amount_received'] = $payload['final_total'] ?? 0;
        $payload['items'] = optional(Table::find($payment->table_id))->listitem;
        $payload['payment_details'] = $payload['details'] ?? [];

        return $payload;
    }

    private function clearTablePayload(): array
    {
        return [
            'status' => self::STATUS_ACTIVE,
            'user_id' => null,
            'payment_id' => null,
            'listitem' => null,
            'number_of_people' => 0,
            'pin' => null,
            'qr_token' => null,
        ];
    }

    private function storeId(Request $request): int
    {
        return (int) $request->input('store_id', config('app.store_id', 1));
    }

    private function userId(Request $request): int
    {
        return (int) $request->input('user_id', 1);
    }

    private function success(string $message, array $payload = [], int $statusCode = 200)
    {
        return response()->json(array_merge([
            'status' => true,
            'status_code' => $statusCode,
            'message' => $message,
        ], $payload), $statusCode);
    }

    private function error($message, int $statusCode)
    {
        return response()->json([
            'status' => false,
            'status_code' => $statusCode,
            'message' => $message,
        ], $statusCode);
    }

    private function processSyncAfterResponse(): void
    {
        app()->terminating(function () {
            try {
                app(SyncService::class)->processQueue(10);
            } catch (\Throwable $th) {
                Log::warning('Edge table post-response sync failed', [
                    'error' => $th->getMessage(),
                ]);
            }
        });
    }
}
