<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KitchenPrintController extends Controller
{
    public function printAll(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return $this->error('Table not found', 404);
        }

        return $this->success($this->tablePrintPayload($table));
    }

    public function printNextWeb(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return $this->error('Table not found', 404);
        }

        return DB::transaction(function () use ($table) {
            $products = $this->listPrintableItems($table, true);
            if (empty($products)) {
                return $this->error('No items to print', 404);
            }

            return $this->success($this->tablePrintPayload($table, $products));
        });
    }

    public function printOnBrowser(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return response()->json(['status' => false, 'message' => 'Table not found'], 404);
        }

        try {
            $products = $this->listPrintableItems($table, true);
            if (empty($products)) {
                return response()->json(['status' => false, 'message' => 'No items to print', 'error_code' => 'no_items'], 404);
            }

            $payload = $this->tablePrintPayload($table, $products);
            $store = Store::find($table->store_id);
            $paperSize = $request->input('paper_size', '80');
            $language = $request->input('language', 'vi');
            app()->setLocale($language);

            // Render kitchen template HTML
            $tplName = 'kitchen.cook_template_print_' . $paperSize;
            if (!view()->exists($tplName)) {
                $tplName = 'kitchen.cook_template_print_80';
            }
            $html = view($tplName, [
                'data' => $payload,
                'payment' => $payload['payment'] ?? [],
                'setting_print_kitchen' => $payload['setting_print_kitchen'] ?? null,
                'bill_setting' => [],
            ])->render();

            return response()->json([
                'status' => true,
                'data' => [
                    'browser' => [
                        'view' => ['default' => $html],
                    ],
                ],
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge kitchen print on browser failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Print render failed'], 500);
        }
    }

    public function checkPrintedStatus(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return $this->error('Table not found', 404);
        }

        $decoded = json_decode($table->listitem, true) ?: [];
        $items = $decoded['item'] ?? $decoded ?? [];
        $notPrintedIds = [];

        foreach ($items as $key => $item) {
            $isPrinted = isset($item['print_status']) && $item['print_status'];
            if (!$isPrinted) {
                $notPrintedIds[] = [
                    'product_id' => $item['id'] ?? $item['product_id'] ?? null,
                    'product_key' => $key,
                ];
            }
        }

        return response()->json([
            'status' => true,
            'status_code' => 200,
            'message' => 'Get info success',
            'data' => [
                'listProductId' => $notPrintedIds,
            ],
        ]);
    }

    private function findTable(Request $request): ?Table
    {
        $tableId = $request->input('table_id', $request->input('id')) ?: $request->input('table.id');
        if (!$tableId) {
            return null;
        }

        $query = Table::with(['payment', 'payment.user'])->whereKey($tableId);
        $storeId = (int) $request->input('store_id', config('app.store_id', 1));
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }

        return $query->first();
    }

    private function tablePrintPayload(Table $table, ?array $products = null): array
    {
        $payment = $table->payment;
        $user = $payment && $payment->user ? $payment->user->toArray() : null;
        $store = Store::find($table->store_id);
        $productsList = $products ?? $this->listItems($table);

        return [
            'id' => $table->id,
            'table_id' => $table->id,
            'tablename' => $table->tablename ?? $table->name ?? null,
            'number_of_people' => $table->number_of_people ?? 0,
            'products' => $productsList,
            'user' => $user,
            'payment_code' => $payment->payment_code ?? null,
            'payment' => $payment ? array_merge($payment->toArray(), [
                'tablename' => $table->tablename ?? $table->name ?? null,
                'products' => $productsList,
                'created_at' => optional($table->created_at)->format('Y-m-d H:i:s'),
                'updated_at' => optional($table->updated_at)->format('Y-m-d H:i:s'),
            ]) : null,
            'created_at' => optional($table->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($table->updated_at)->format('Y-m-d H:i:s'),
            'setting_print_kitchen' => $store ? $store->setting_print_kitchen : null,
        ];
    }

    private function listItems(Table $table): array
    {
        if (!$table->listitem) {
            return [];
        }

        $decoded = json_decode($table->listitem, true) ?: [];

        return collect($decoded['item'] ?? $decoded ?? [])
            ->map(function ($item) {
                if (!isset($item['diff_quantity'])) {
                    $item['diff_quantity'] = $item['quantity'] ?? 0;
                }

                return $item;
            })
            ->values()
            ->all();
    }

    private function listPrintableItems(Table $table, bool $markPrinted = false): array
    {
        if (!$table->payment || !$table->listitem) {
            return [];
        }

        $decoded = json_decode($table->listitem, true) ?: [];
        $rawItems = $decoded['item'] ?? $decoded ?? [];

        $details = $table->payment->details()
            ->whereColumn('printed_quantity', '<', 'quantity')
            ->whereNull('deleted_at')
            ->orderBy('id', 'asc')
            ->get();

        Log::debug('KitchenPrint listPrintableItems', [
            'table_id' => $table->id,
            'payment_id' => $table->payment_id,
            'has_payment' => $table->payment ? 'yes' : 'no',
            'all_details_count' => $table->payment ? $table->payment->details()->withoutGlobalScope('Illuminate\Database\Eloquent\SoftDeletingScope')->count() : 0,
            'active_details_count' => $table->payment ? $table->payment->details()->count() : 0,
            'where_count' => $details->count(),
        ]);

        $items = [];
        foreach ($details as $detail) {
            $printCount = (int) $detail->quantity - (int) $detail->printed_quantity;
            if ($printCount <= 0) {
                continue;
            }

            $item = $this->findListItemForDetail($rawItems, $detail);
            if (!$item) {
                $item = [
                    'id' => $detail->product_id,
                    'product_id' => $detail->product_id,
                    'quantity' => $detail->quantity,
                    'price' => $detail->price,
                    'total' => $detail->total,
                    'note' => $detail->note,
                ];
            }

            $item['diff_quantity'] = $printCount;
            $item['printed_quantity'] = (int) $detail->printed_quantity + $printCount;
            $item['print_status'] = ((int) $detail->printed_quantity + $printCount) >= (int) $detail->quantity;

            $items[] = $item;

            if ($markPrinted) {
                $detail->increment('printed_quantity', $printCount);
            }
        }

        if ($markPrinted && !empty($items)) {
            $this->syncPrintedStateToTableListItem($table);
        }

        Log::debug('KitchenPrint listPrintableItems result', ['items_count' => count($items)]);

        return $items;
    }

    private function findListItemForDetail(array $rawItems, $detail): ?array
    {
        if (!empty($detail->product_key) && isset($rawItems[$detail->product_key]) && is_array($rawItems[$detail->product_key])) {
            return $rawItems[$detail->product_key];
        }

        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $item['product_id'] ?? $item['id'] ?? null;
            if ((string) $productId === (string) $detail->product_id) {
                return $item;
            }
        }

        return null;
    }

    private function syncPrintedStateToTableListItem(Table $table): void
    {
        $decoded = json_decode($table->listitem, true) ?: [];
        $rawItems = $decoded['item'] ?? $decoded ?? [];
        $details = $table->payment->details()->whereNull('deleted_at')->get();

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

    private function success(array $data)
    {
        return response()->json([
            'status' => true,
            'status_code' => 200,
            'message' => 'Get info success',
            'data' => $data,
        ]);
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
