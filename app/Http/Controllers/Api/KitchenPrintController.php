<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use Illuminate\Http\Request;

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

        return $this->success($this->tablePrintPayload($table));
    }

    public function printOnBrowser(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return $this->error('Table not found', 404);
        }

        return $this->success($this->tablePrintPayload($table));
    }

    public function checkPrintedStatus(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return $this->error('Table not found', 404);
        }

        $items = $this->listItems($table);
        $notPrintedIds = collect($items)
            ->filter(fn ($item) => !isset($item['print_status']) || !$item['print_status'])
            ->map(fn ($item) => $item['id'] ?? $item['product_id'] ?? null)
            ->filter()
            ->values()
            ->all();

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
        $tableId = $request->input('table_id', $request->input('id'));
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

    private function tablePrintPayload(Table $table): array
    {
        $payment = $table->payment;
        $user = $payment && $payment->user ? $payment->user->toArray() : null;

        return [
            'id' => $table->id,
            'table_id' => $table->id,
            'tablename' => $table->tablename ?? $table->name ?? null,
            'number_of_people' => $table->number_of_people ?? 0,
            'products' => $this->listItems($table),
            'user' => $user,
            'payment_code' => $payment->payment_code ?? null,
            'payment' => $payment ? $payment->toArray() : null,
            'created_at' => optional($table->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($table->updated_at)->format('Y-m-d H:i:s'),
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
