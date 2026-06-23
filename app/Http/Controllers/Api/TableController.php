<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Table;
use App\Models\User;
use App\Models\Store;
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
            ->when($storeId, fn($query) => $query->where('store_id', $storeId))
            ->where('status', '!=', -1)
            ->orderBy('id')
            ->get()
            ->map(fn(Table $table) => $this->tablePayload($table));

        return $this->success('api.table_get_list', ['table' => $tables]);
    }

    public function show(Request $request, $id = null)
    {
        $tableId = $id ?: $request->input('id') ?: $request->input('table_id');
        $table = $this->findTable($tableId, $request);
        if (!$table) {
            return $this->error('api.table_empty', 404);
        }

        return response()->json([
            'status' => true,
            'status_code' => 200,
            'message' => __('api.table_get'),
            'data' => ['data_table' => [$this->tablePayload($table)]],
        ]);
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

            $userId = $this->userId($request);
            $currentTime = now()->toDateTimeString();
            $lockTime = now()->addMinutes(5)->toDateTimeString();

            // Set language locale for translated error messages
            $locale = $request->input('isCheckLanguage', 'vi');
            app()->setLocale($locale);

            // Match Cloud logic: if table is busy (can_order=0), verify lock/user
            if ($table->can_order == 0) {
                $conditions = [
                    empty($table->user_id),
                    $userId == $table->user_id,
                    $currentTime > $table->lock_time,
                    $table->can_order == 1,
                ];

                if (!in_array(true, $conditions, true) && !$request->has('force')) {
                    $busyUser = User::find($table->user_id);
                    $userName = $busyUser ? $busyUser->name : 'Unknown';
                    return $this->error(__('api.table_in_use', ['name' => $userName]), 409);
                }
            }

            // Check-in does NOT change status, only sets can_order=0 and lock_time to prevent conflicts
            $table->fill([
                'user_id' => $userId,
                'can_order' => 0,
                'lock_time' => $lockTime,
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

                $paymentId = $request->input('payment_id') ?: $table->payment_id;
                if ($paymentId) {
                    Payment::whereKey($paymentId)->update([
                        'status' => self::PAYMENT_CANCELLED,
                        'note' => $request->input('reason'),
                        'reason' => $request->input('reason'),
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

                Log::debug('Edge updateOrder items', [
                    'table_id' => $table->id,
                    'items_count' => count($items),
                    'first_item' => $items[0] ?? null,
                ]);

                $payment = $this->upsertPendingPayment($request, $table, $items, $summary, $listitem);

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

            return $this->success('front/pos_order.Cập nhật món thành công', [
                'data' => [
                    'table' => $this->tablePayload($result['table']),
                    'payment' => $this->paymentPayload($result['payment']),
                ],
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge table update order failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
                'request_id' => $request->input('id'),
            ]);

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

    private function upsertPendingPayment(Request $request, Table $table, array $items, array $summary, ?string $rawListitem = null): Payment
    {
        $payment = $request->filled('payment_id')
            ? Payment::find($request->input('payment_id'))
            : ($table->payment_id ? Payment::find($table->payment_id) : null);

        $storeId = $this->storeId($request);
        $store = \App\Models\Store::find($storeId);
        $isTaxIncluded = $store ? ($store->is_tax_included ?? false) : false;

        $calcItems = [];
        foreach ($items as $item) {
            $key = $item['product_key'] ?: 'product:' . $item['product_id'];
            $calcItems[$key] = $item;
        }

        $calcAttributes = [
            'split_merge_item' => $calcItems,
            'discount' => (float) $request->input('discount', 0),
            'surcharge' => (float) $request->input('surcharge', 0),
            'type_discount' => $request->input('type_discount', 'amount'),
            'discount_percent' => (int) $request->input('discount_percent', 0),
            'surcharge_percent' => (int) $request->input('surcharge_percent', 0),
            'service_charge' => (int) $request->input('service_charge', 0),
            'is_senior_discount' => $request->input('is_senior_discount', false),
            'senior_discount_amount' => (float) $request->input('senior_discount_amount', 0),
            'amount_received' => (float) $request->input('amount_received', 0),
        ];

        $calcResult = $this->buildSimplePayment($calcAttributes, $isTaxIncluded);

        $payload = [
            'store_id' => $storeId,
            'table_id' => $table->id,
            'customer_id' => $request->input('customer_id') ?: null,
            'paid_date' => now(),
            'total' => $isTaxIncluded ? $calcResult['total_incl_vat_before_discount'] : $calcResult['sub_total_before_discount'],
            'discount' => $calcResult['discount'],
            'tax' => $calcResult['total_tax'],
            'final_total' => $calcResult['valuetotal'],
            'payment_method' => (int) ($request->input('payment_method', 1) ?: 1),
            'note' => $request->input('reason'),
            'status' => (int) $request->input('status', self::PAYMENT_PENDING),
            'user_id' => $this->userId($request),
            'type_discount' => $request->input('type_discount', 'amount'),
            'discount_percent' => (int) $request->input('discount_percent', 0),
            'surcharge' => $calcResult['surcharge'],
            'surcharge_percent' => (int) $request->input('surcharge_percent', 0),
            'surcharge_reason' => $request->input('surcharge_reason'),
            'service_charge' => $calcResult['service_charge'] ?? (int) $request->input('service_charge', 0),
            'service_charge_amount' => $calcResult['service_charge_amount'] ?? 0.0,
            'is_senior_discount' => $request->input('is_senior_discount', false),
            'senior_discount_amount' => $calcResult['senior_discount_amount'] ?? 0.0,
            'sub_total_before_discount' => $calcResult['sub_total_before_discount'],
            'total_incl_vat_before_discount' => $calcResult['total_incl_vat_before_discount'],
            'amount_received' => $calcResult['amount_received'],
            'items' => $this->buildItemsPayload($rawListitem, $calcResult, $request),
        ];

        if ($payment) {
            $payment->update($payload);
        } else {
            $payload['payment_code'] = 'EDGE-' . now()->format('YmdHis') . '-' . random_int(1000, 9999);
            $payment = Payment::create($payload);
        }

        // Clear table_id on other payments for the same table to prevent duplicates
        if ($table->id) {
            Payment::where('table_id', $table->id)
                ->where('id', '!=', $payment->id)
                ->whereNull('deleted_at')
                ->update(['table_id' => null]);
        }

        $printedQuantities = $payment->details()
                ->whereNull('deleted_at')
                ->get()
                ->mapWithKeys(function ($detail) {
                    $key = $detail->product_key ?: 'product:' . $detail->product_id;

                    return [
                        $key => [
                            'printed_quantity' => (int) $detail->printed_quantity,
                            'served' => (bool) $detail->served,
                        ]
                    ];
                });

            $payment->details()->delete();
            foreach ($items as $item) {
                $detailKey = $item['product_key'] ?: 'product:' . $item['product_id'];
                $previousData = $printedQuantities[$detailKey] ?? [];
                // Fallback: match by product_id if key not found (e.g. after re-order)
                if (empty($previousData)) {
                    foreach ($printedQuantities as $pk => $pd) {
                        $fallbackKey = 'product:' . ($item['product_id'] ?? 0);
                        if ($pk === $detailKey || $pk === $fallbackKey) {
                            $previousData = $pd;
                            break;
                        }
                    }
                }
            $printedQuantity = min((int) ($previousData['printed_quantity'] ?? 0), (int) $item['quantity']);
            $served = $previousData['served'] ?? false;

            $calcItem = $calcResult['items'][$detailKey] ?? [];

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
                'served' => $served,
                'detail_discount' => $calcItem['detail_discount'] ?? 0.0,
                'tax_amount' => $calcItem['tax_amount'] ?? 0.0,
                'detail_discount_excluding_tax' => $calcItem['detail_discount_excluding_tax'] ?? 0.0,
                'unit_price_excluding_tax' => $calcItem['unit_price_excluding_tax'] ?? 0.0,
                'discounted_price_excluding_tax' => $calcItem['discounted_price_excluding_tax'] ?? 0.0,
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
            $vat = (float) ($item['vat'] ?? 0);

            $items[] = [
                'product_id' => (int) $productId,
                'product_key' => is_string($productKey) ? $productKey : null,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $total,
                'vat' => $vat,
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
            $total = array_sum(array_map(fn($item) => (float) $item['total'], $items));
        }

        return ['total' => $total];
    }

    private function buildItemsPayload(?string $rawListitem, array $calcResult, Request $request): string
    {
        // Decode raw listitem preserving all original fields (title, product_code, image, etc.)
        $rawItems = [];
        if ($rawListitem) {
            $decoded = json_decode($rawListitem, true) ?: [];
            $rawItems = $decoded['item'] ?? $decoded ?? [];
        }

        // Merge original fields with calculated fields
        $mergedItems = [];
        foreach ($calcResult['items'] ?? [] as $key => $calcItem) {
            $rawItem = $rawItems[$key] ?? [];
            $mergedItems[$key] = array_merge($rawItem, $calcItem);
            $mergedItems[$key]['id'] = (int) ($rawItem['id'] ?? $calcItem['product_id'] ?? 0);
        }

        return json_encode([
            'item' => $mergedItems,
            'discountPayment' => $calcResult['discount'] ?? 0,
            'reasonSurcharge' => $request->input('surcharge_reason'),
            'surcharge' => $calcResult['surcharge'] ?? 0,
            'total_tax' => $calcResult['total_tax'] ?? 0,
        ]);
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
        $payload['valuetotal'] = $payload['final_total'] ?? 0;
        $payload['total_tax'] = $payload['tax'] ?? 0;
        $payload['amount_received'] = $payload['amount_received'] ?? ($payload['final_total'] ?? 0);
        $payload['payment_details'] = array_map(function ($detail) {
            $detail['total_price'] = $detail['total_price'] ?? ($detail['total'] ?? 0);
            return $detail;
        }, $payload['details'] ?? []);

        return $payload;
    }

    private function clearTablePayload(): array
    {
        return [
            'status' => self::STATUS_ACTIVE,
            'user_id' => null,
            'payment_id' => null,
            'listitem' => null,
            'userordered' => null,
            'number_of_people' => 0,
            'pin' => null,
            'qr_token' => null,
            'lock_time' => null,
            'can_order' => 1,
            'is_order_enabled' => 1,
        ];
    }

    private function storeId(Request $request): int
    {
        return (int) (
            $request->input('store_id')
            ?: $request->header('X-Store-ID')
            ?: config('app.store_id', 1)
        );
    }

    private function userId(Request $request): int
    {
        return (int) $request->input('user_id', 1);
    }

    private function success(string $message, array $payload = [], int $statusCode = 200)
    {
        app()->setLocale(request()->input('isCheckLanguage', 'vi'));
        return response()->json(array_merge([
            'status' => true,
            'status_code' => $statusCode,
            'message' => __($message),
        ], $payload), $statusCode);
    }

    private function error($message, int $statusCode)
    {
        app()->setLocale(request()->input('isCheckLanguage', 'vi'));
        return response()->json([
            'status' => false,
            'status_code' => $statusCode,
            'message' => is_string($message) ? __($message) : $message,
        ], $statusCode);
    }

    private function processSyncAfterResponse(): void
    {
        // Sync runs via background SyncWorker — no blocking needed
    }

    public function getServedStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'table_id' => ['required'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'status_code' => 400,
                    'messages' => $validator->errors(),
                ], 400);
            }

            $table = Table::find($request->input('table_id'));
            if (!$table || !$table->payment_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Không tìm thấy bàn hoặc payment_id',
                    'status_code' => 404,
                ], 404);
            }

            $details = PaymentDetail::where('payment_id', $table->payment_id)->get();

            if ($details->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Không tìm thấy sản phẩm trong payment detail',
                    'status_code' => 404,
                ], 404);
            }

            $data = $details->map(function ($detail) {
                return [
                    'product_id' => (int) $detail->product_id,
                    'served' => (int) $detail->served,
                    'product_key' => $detail->product_key,
                ];
            });

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'data' => $data,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Edge getServedStatus failed: ' . $th->getMessage());
            return $this->error('api.ISError', 500);
        }
    }

    public function served(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'table_id' => ['required'],
                'product_id' => ['required'],
                'product_key' => ['required'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'status_code' => 400,
                    'messages' => $validator->errors(),
                ], 400);
            }

            $table = Table::find($request->input('table_id'));

            if (!$table || !$table->payment_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Không tìm thấy bàn hoặc payment_id',
                    'status_code' => 404,
                ], 404);
            }

            $served = $request->has('served') ? (bool) $request->input('served') : true;

            $updated = PaymentDetail::where('payment_id', $table->payment_id)
                ->where('product_id', $request->input('product_id'))
                ->where('product_key', $request->input('product_key'))
                ->update(['served' => $served ? 1 : 0]);

            if (!$updated) {
                return $this->error('Cập nhật trạng thái phục vụ thất bại', 400);
            }

            $this->processSyncAfterResponse();

            return $this->success('Cập nhật trạng thái phục vụ thành công');
        } catch (\Throwable $th) {
            Log::error('Edge served failed: ' . $th->getMessage());
            return $this->error('api.ISError', 500);
        }
    }

    public function updateNumberOfPeople(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'data' => ['required', 'array'],
                'number_of_people' => ['required', 'integer', 'min:1'],
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'status_code' => 400, 'message' => $validator->errors()], 400);
            }

            $data = $request->input('data');
            $tableId = $data['table_id'] ?? null;
            $numberOfPeople = (int) $request->input('number_of_people');

            if (!$tableId) {
                return response()->json(['status' => false, 'status_code' => 400, 'message' => 'table_id is required'], 400);
            }

            $table = Table::find($tableId);
            if (!$table) {
                return response()->json(['status' => false, 'status_code' => 404, 'message' => 'Table not found'], 404);
            }

            $table->number_of_people = $numberOfPeople;
            $table->save();

            return response()->json(['status' => true, 'status_code' => 200, 'message' => 'Cập nhật số lượng khách thành công']);
        } catch (\Throwable $th) {
            Log::error('Edge updateNumberOfPeople failed: ' . $th->getMessage());
            return response()->json(['status' => false, 'status_code' => 500, 'message' => 'Cập nhật số lượng khách không thành công'], 500);
        }
    }

    public function getPaymentMethods(Request $request)
    {
        try {
            $storeId = $this->storeId($request);
            $methods = \App\Models\PaymentMethod::where('store_id', $storeId)->get();

            $data = $methods->map(function ($method) {
                return [
                    'id' => (int) $method->id,
                    'value' => (string) $method->value,
                    'name' => (string) $method->name,
                ];
            });

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'data' => $data,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Edge getPaymentMethods failed: ' . $th->getMessage());
            return $this->error('api.ISError', 500);
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
            $paymentDetails[] = [
                'quantity' => $value['quantity'],
                'price' => $value['price'],
                'total_price' => $value['TotalPrice'] ?? $value['total'] ?? 0,
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
            'items' => $billItem['items'],
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
}
