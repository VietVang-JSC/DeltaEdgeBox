<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    public function createPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'status_code' => 400,
                'message' => $validator->errors(),
            ], 400);
        }

        try {
            $items = $this->decodeItems($request->input('items'));
            $summary = $this->summarizeItems($items);

            $payment = DB::transaction(function () use ($request, $items, $summary) {
                $payment = Payment::create([
                    'store_id' => $request->input('store_id', config('app.store_id')),
                    'table_id' => $request->input('table_id', $request->input('tableID')),
                    'customer_id' => $request->input('customer_id'),
                    'paid_date' => now(),
                    'total' => $request->input('valuetotal', $summary['total']),
                    'discount' => (float) $request->input('discount', 0),
                    'tax' => (float) $request->input('total_tax', 0),
                    'final_total' => $request->input('amount_received', $request->input('valuetotal', $summary['total'])),
                    'payment_method' => $request->input('payment_method', 'cash') ?: 'cash',
                    'note' => $request->input('reason'),
                    'status' => (int) $request->input('status', 1),
                    'user_id' => (int) $request->input('user_id', 1),
                ]);

                foreach ($items as $item) {
                    PaymentDetail::create([
                        'payment_id' => $payment->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'total' => $item['total'],
                        'note' => $item['note'] ?? null,
                    ]);
                }

                return $payment->load('details');
            });

            app()->terminating(function () {
                try {
                    app(SyncService::class)->processQueue(10);
                } catch (\Throwable $th) {
                    Log::warning('Edge payment post-response sync failed', [
                        'error' => $th->getMessage(),
                    ]);
                }
            });

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'api.payment_create',
                'data' => [
                    'payment' => $payment->toArray(),
                ],
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge payment create failed', [
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => 'api.ISError',
            ], 500);
        }
    }

    private function decodeItems($items): array
    {
        $decoded = is_string($items) ? json_decode($items, true) : $items;
        $rawItems = $decoded['item'] ?? $decoded ?? [];

        return array_values(array_filter(array_map(function ($item) {
            if (!is_array($item)) {
                return null;
            }

            $productId = $item['product_id'] ?? $item['id'] ?? null;
            if (empty($productId)) {
                return null;
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);

            return [
                'product_id' => (int) $productId,
                'quantity' => $quantity,
                'price' => $price,
                'total' => (float) ($item['TotalPrice'] ?? $item['total'] ?? ($price * $quantity)),
                'note' => $item['note'] ?? null,
            ];
        }, $rawItems)));
    }

    private function summarizeItems(array $items): array
    {
        return [
            'total' => array_sum(array_map(function ($item) {
                return (float) ($item['total'] ?? 0);
            }, $items)),
        ];
    }
}
