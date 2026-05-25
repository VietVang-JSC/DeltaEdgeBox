<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Table;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PosWebFilterController extends Controller
{
    public function filter(Request $request)
    {
        try {
            $storeId = $this->storeId($request);
            $user = $this->userPayload($request, $storeId);
            $products = $this->products($storeId);
            $categories = $this->categories($storeId);
            $customers = $this->customers($storeId);
            $payments = $this->pendingPayments($storeId);
            $tables = $this->tables($storeId);

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'success',
                'data' => [
                    'data_users' => [$user],
                    'data_product' => $products,
                    'category_list' => $categories,
                    'customer_list' => $customers,
                    'data_payment' => $payments,
                    'data_table' => $tables,
                    'data_bookings' => [],
                    'threshold_message' => [],
                    'total_records_product' => count($products),
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Edge posWeb filter failed', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => 'edge.pos_filter_failed',
            ], 500);
        }
    }

    private function userPayload(Request $request, int $storeId): array
    {
        $userId = data_get($request->input('users', []), 'query.id');
        $user = $userId ? User::find($userId) : User::where('store_id', $storeId)->first();
        $store = Store::find($storeId) ?: Store::first();

        $payload = $user ? $user->toArray() : [
            'id' => $userId ?: 1,
            'store_id' => $storeId,
            'name' => 'Edge User',
        ];

        $payload['store'] = $store ? $store->toArray() : [
            'id' => $storeId,
            'time_zone' => 'Asia/Ho_Chi_Minh',
            'is_tax_included' => 0,
            'service_charge' => 0,
        ];

        $payload['store']['time_zone'] = $payload['store']['time_zone'] ?? 'Asia/Ho_Chi_Minh';
        $payload['store']['is_tax_included'] = $payload['store']['is_tax_included'] ?? 0;
        $payload['store']['service_charge'] = $payload['store']['service_charge'] ?? 0;

        return $payload;
    }

    private function products(int $storeId): array
    {
        return Product::query()
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->where('status', 1)
            ->limit(500)
            ->get()
            ->map(fn (Product $product) => $this->productPayload($product))
            ->values()
            ->all();
    }

    private function productPayload(Product $product): array
    {
        $payload = $product->toArray();
        $payload['product_code'] = $payload['product_code'] ?? $payload['code'] ?? (string) $product->id;
        $payload['title'] = $payload['title'] ?? $payload['name'] ?? '';
        $payload['price'] = $payload['price'] ?? $payload['sale_price'] ?? 0;
        $payload['price_after_tax'] = $payload['price_after_tax'] ?? $payload['price'];
        $payload['vat'] = $payload['vat'] ?? 0;
        $payload['product_extra_list'] = $payload['product_extra_list'] ?? [];
        $payload['combo_products'] = $payload['combo_products'] ?? [];
        $payload['optional_products'] = $payload['optional_products'] ?? [];
        $payload['totalQuantity'] = $payload['totalQuantity'] ?? ($payload['quantity'] ?? 0);
        $payload['minQuantity'] = $payload['minQuantity'] ?? 0;
        $payload['inventory_required'] = $payload['inventory_required'] ?? 0;
        $payload['second_product_code'] = $payload['second_product_code'] ?? null;
        $payload['third_product_code'] = $payload['third_product_code'] ?? null;

        return $payload;
    }

    private function categories(int $storeId): array
    {
        return Category::query()
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->where('status', 1)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Category $category) {
                $payload = $category->toArray();
                $payload['title'] = $payload['title'] ?? $payload['name'] ?? '';
                $payload['category_name'] = $payload['category_name'] ?? $payload['name'] ?? '';

                return $payload;
            })
            ->values()
            ->all();
    }

    private function customers(int $storeId): array
    {
        return Customer::query()
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->get()
            ->values()
            ->toArray();
    }

    private function pendingPayments(int $storeId): array
    {
        return Payment::with(['details.product', 'user', 'customer'])
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->where('status', 0)
            ->whereNull('table_id')
            ->get()
            ->map(fn (Payment $payment) => $this->paymentPayload($payment))
            ->values()
            ->all();
    }

    private function tables(int $storeId): array
    {
        return Table::with('payment.details.product')
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->where('status', '!=', -1)
            ->get()
            ->map(function (Table $table) {
                $payload = $table->toArray();
                $payload['tablename'] = $payload['tablename'] ?? $payload['name'] ?? '';
                $payload['payment'] = $table->payment ? $this->paymentPayload($table->payment) : null;

                return $payload;
            })
            ->values()
            ->all();
    }

    private function paymentPayload(Payment $payment): array
    {
        $payload = $payment->toArray();
        $payload['payment_code'] = $payload['payment_code'] ?? 'EDGE-' . $payment->id;
        $payload['reason'] = $payload['reason'] ?? ($payload['note'] ?? '');
        $payload['valuetotal'] = $payload['valuetotal'] ?? ($payload['total'] ?? 0);
        $payload['total_tax'] = $payload['total_tax'] ?? ($payload['tax'] ?? 0);
        $payload['amount_received'] = $payload['amount_received'] ?? ($payload['final_total'] ?? 0);
        $payload['sub_total_before_discount'] = $payload['sub_total_before_discount'] ?? ($payload['total'] ?? 0);
        $payload['total_incl_vat_before_discount'] = $payload['total_incl_vat_before_discount'] ?? ($payload['total'] ?? 0);
        $payload['payment_details'] = $payment->details
            ->map(function ($detail) {
                $item = $detail->toArray();
                $item['total_price'] = $item['total_price'] ?? ($item['total'] ?? 0);
                $item['products'] = $detail->product ? $this->productPayload($detail->product) : ['vat' => 0];

                return $item;
            })
            ->values()
            ->all();

        return $payload;
    }

    private function storeId(Request $request): int
    {
        $userStoreId = data_get($request->input('users', []), 'query.store_id');

        return (int) ($request->input('store_id') ?: $userStoreId ?: config('app.store_id', 1));
    }
}
