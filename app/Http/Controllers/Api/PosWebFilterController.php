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
    private function defaultTimeZone(): string
    {
        return config('app.timezone', 'Asia/Ho_Chi_Minh');
    }

    public function filter(Request $request)
    {
        try {
            $storeId = $this->storeId($request);
            $user = $this->userPayload($request, $storeId);
            $products = $this->products($storeId, $request);
            $categories = $this->categories($storeId);
            $customers = $this->customers($storeId);
            $payments = $this->pendingPayments($storeId);
            $tables = $this->tables($storeId);
            $store = $this->storePayload($storeId);
            $billSetting = $this->billSettingPayload($store);
            $bankPayment = $this->bankPaymentPayload();

            // Check if pagination is requested
            $pagination = $request->input('products.clauses.pagination');
            $dataProduct = $products;
            if ($pagination) {
                $pageSize = (int)data_get($pagination, 'pageSize', 15);
                $currentPage = (int)data_get($pagination, 'currentPage', 1);
                $offset = ($currentPage - 1) * $pageSize;
                
                $paginatedList = array_slice($products, $offset, $pageSize);
                $dataProduct = [
                    'data_list' => $paginatedList,
                    'total' => count($products),
                    'pageSize' => $pageSize,
                    'currentPage' => $currentPage,
                ];
            }

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'success',
                'data' => [
                    'data_users' => [$user],
                    'data_product' => $dataProduct,
                    'category_list' => $categories,
                    'customer_list' => $customers,
                    'data_payment' => $payments,
                    'data_table' => $tables,
                    'data_bookings' => [],
                    'threshold_message' => [],
                    'data_stores' => [$store],
                    'data_bill_setting' => [$billSetting],
                    'data_bank_payment' => [$bankPayment],
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
            'time_zone' => $this->defaultTimeZone(),
            'is_tax_included' => 0,
            'service_charge' => 0,
        ];

        $payload['store']['time_zone'] = $payload['store']['time_zone'] ?? $this->defaultTimeZone();
        $payload['store']['is_tax_included'] = $payload['store']['is_tax_included'] ?? 0;
        $payload['store']['service_charge'] = $payload['store']['service_charge'] ?? 0;

        return $payload;
    }

    private function storePayload(int $storeId): array
    {
        $store = Store::find($storeId) ?: Store::first();
        $payload = $store ? $store->toArray() : [
            'id' => $storeId,
            'name' => 'Edge Store',
            'code' => 'EDGE-' . $storeId,
            'address' => null,
            'phone' => null,
            'email' => null,
            'status' => true,
        ];

        $payload['id'] = $payload['id'] ?? $storeId;
        $payload['time_zone'] = $payload['time_zone'] ?? $this->defaultTimeZone();
        $payload['is_tax_included'] = $payload['is_tax_included'] ?? 0;
        $payload['setting_print_kitchen'] = $payload['setting_print_kitchen'] ?? [];
        $payload['service_charge'] = $payload['service_charge'] ?? 0;
        $payload['qr_footer_text'] = $payload['qr_footer_text'] ?? '';
        $payload['deployment_mode'] = $payload['deployment_mode'] ?? 'offline-first';
        $payload['edge_routing_active'] = $payload['edge_routing_active'] ?? true;
        $payload['edge_box_url'] = $payload['edge_box_url'] ?? config('app.url');
        $payload['edge_box_store_id'] = $payload['edge_box_store_id'] ?? $storeId;
        $payload['edge_enabled_at'] = $payload['edge_enabled_at'] ?? null;
        $payload['edge_config_version'] = $payload['edge_config_version'] ?? 1;

        return $payload;
    }

    private function billSettingPayload(array $store): array
    {
        return [
            'store_name' => $store['name'] ?? 'Edge Store',
            'store_address' => $store['address'] ?? '',
            'store_phone' => $store['phone'] ?? '',
            'footer_content' => $store['qr_footer_text'] ?? '',
            'wifi_information' => '',
            'logo' => '',
            'qr_footer_text' => $store['qr_footer_text'] ?? '',
        ];
    }

    private function bankPaymentPayload(): array
    {
        return [
            'bank_code' => '',
            'account_number' => '',
            'account_owner' => '',
        ];
    }

    private function products(int $storeId, Request $request = null): array
    {
        $store = Store::find($storeId) ?: Store::first();
        $timezone = $store ? ($store->time_zone ?? 'Asia/Ho_Chi_Minh') : 'Asia/Ho_Chi_Minh';
        $isTaxIncluded = $store ? (int) ($store->is_tax_included ?? 0) : 0;

        $query = Product::query()
            ->with('timePrices')
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->where('status', 1);

        if ($request) {
            $whereRaw = $request->input('products.query.WhereRaw');
            if ($whereRaw) {
                $query->whereRaw($whereRaw);
            }
        }

        return $query->limit(500)
            ->get()
            ->map(fn (Product $product) => $this->productPayload($product, $timezone, $isTaxIncluded))
            ->values()
            ->all();
    }

    private function applyTimePrice(Product $product, string $timezone, &$availableFrames = []): Product
    {
        $now = now()->setTimezone($timezone);
        $currentDay = $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        $timePrices = $product->timePrices ?? collect();
        $applied = false;

        foreach ($timePrices as $tp) {
            if (empty($tp->is_active)) {
                continue;
            }

            $days = $tp->days_of_week;

            if (is_array($days) && in_array($currentDay, $days)) {
                $availableFrames[] = substr($tp->start_time, 0, 5) . '-' . substr($tp->end_time, 0, 5);

                if (!$applied && $currentTime >= $tp->start_time && $currentTime <= $tp->end_time) {
                    $product->price = $tp->price ?? $product->price;
                    $product->price_after_tax = $tp->price_after_tax ?? $product->price_after_tax;
                    $applied = true;
                }
            }
        }

        return $product;
    }

    private function productPayload(Product $product, string $timezone = null, ?int $isTaxIncluded = null): array
    {
        if ($timezone === null || $isTaxIncluded === null) {
            $store = Store::find($product->store_id) ?: Store::first();
            $timezone = $timezone ?? ($store ? ($store->time_zone ?? 'Asia/Ho_Chi_Minh') : 'Asia/Ho_Chi_Minh');
            $isTaxIncluded = $isTaxIncluded ?? ($store ? (int) ($store->is_tax_included ?? 0) : 0);
        }
        $availableFrames = [];
        $product = $this->applyTimePrice($product, $timezone, $availableFrames);

        $payload = $product->toArray();
        $payload['product_code'] = $payload['product_code'] ?? $payload['code'] ?? (string) $product->id;
        $payload['title'] = $payload['title'] ?? $payload['name'] ?? '';
        $payload['price_after_tax'] = $product->price_after_tax ?? $product->price ?? 0;
        $payload['unit_price'] = $product->price ?? 0;
        $payload['price'] = $isTaxIncluded == 0 ? ($product->price ?? 0) : ($product->price_after_tax ?? 0);
        $payload['vat'] = $payload['vat'] ?? 0;
        $payload['tax_name'] = $this->taxName($payload['vat']);
        $payload['original_tax'] = $payload['vat'] < 0 ? $payload['vat'] : null;
        $payload['product_extra_list'] = $payload['product_extra_list'] ?? [];
        $payload['product_extras'] = $payload['product_extras'] ?? $payload['product_extra_list'];
        $payload['combo_products'] = $payload['combo_products'] ?? [];
        $payload['optional_products'] = $payload['optional_products'] ?? [];
        $payload['number_of_options'] = $payload['number_of_options'] ?? 0;
        $payload['types'] = $payload['types'] ?? ['product_types' => []];
        $payload['time_prices'] = $payload['time_prices'] ?? [];
        $payload['product_time_prices'] = $payload['product_time_prices'] ?? $payload['time_prices'];
        $payload['is_restricted_time'] = $payload['is_restricted_time'] ?? 0;
        $payload['available_frames'] = $availableFrames;
        $payload['totalQuantity'] = $payload['totalQuantity'] ?? ($payload['quantity'] ?? 0);
        $payload['minQuantity'] = $payload['minQuantity'] ?? 0;
        $payload['inventory_required'] = $payload['inventory_required'] ?? 0;
        $payload['second_product_code'] = $payload['second_product_code'] ?? null;
        $payload['third_product_code'] = $payload['third_product_code'] ?? null;

        // Load category relation for admin product list view
        if (!isset($payload['category']) && $product->category_id) {
            $category = Category::find($product->category_id);
            $payload['category'] = $category ? $category->toArray() : null;
            if ($payload['category']) {
                $payload['category']['category_name'] = $payload['category']['category_name'] ?? $payload['category']['name'] ?? '';
            }
        }

        return $payload;
    }

    //xu ly tax name giong cloud hien tai tai file ETaxName.php
    private function taxName($vat): string
    {
        $map = [
            -3 => 'Khác',
            -2 => 'Không kê khai',
            -1 => 'Không thuế',
            0  => '0%',
            5  => '5%',
            8  => '8%',
            10 => '10%',
        ];
        return $map[$vat] ?? '0%';
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

    public function apiEdgeFilterByCondition(Request $request)
    {
         //dd($request->all(), $request->getContent());
        try {

            $params = $request->all();

            $response = [];

            /**
             * USERS
             */
            if (!empty($params['users'])) {

                $userQuery = User::with('store');

                if (!empty($params['users']['query']['id'])) {
                    $userQuery->where('id', $params['users']['query']['id']);
                }

                $response['data_users'] = $userQuery->get()->toArray();
            }

            /**
             * CUSTOMERS
             */
            if (array_key_exists('customers', $params)) {

                $response['customer_list'] = Customer::query()
                    ->orderBy('id')
                    ->get()
                    ->toArray();
            }

            /**
             * PAYMENTS
             */
            if (!empty($params['payments'])) {

                $paymentQuery = Payment::query();

                $query = $params['payments']['query'] ?? [];

                foreach ($query as $column => $value) {

                    if ($value === null || $value === '') {
                        continue;
                    }

                    if (is_array($value)) {
                        $paymentQuery->whereIn($column, $value);
                    } else {
                        $paymentQuery->where($column, $value);
                    }
                }

                /**
                 * Relationship
                 */
                $relationships = $params['payments']['relationship'] ?? [];

                if (!empty($relationships)) {
                    $paymentQuery->with($relationships);
                }

                /**
                 * Order By
                 */
                if (!empty($params['payments']['clauses']['orderby'])) {

                    $orderBy = $params['payments']['clauses']['orderby'];

                    $paymentQuery->orderBy(
                        $orderBy['column'] ?? 'updated_at',
                        $orderBy['value'] ?? 'DESC'
                    );
                }

                /**
                 * Pagination
                 */
                $pageSize = $params['payments']['clauses']['pagination']['pageSize'] ?? 15;
                $currentPage = $params['payments']['clauses']['pagination']['currentPage'] ?? 1;

                $payments = $paymentQuery->paginate(
                    $pageSize,
                    ['*'],
                    'page',
                    $currentPage
                );

                $response['data_payment'] = [
                    'data_list'   => $payments->items(),
                    'total'       => $payments->total(),
                    'currentPage' => $payments->currentPage(),
                    'pageSize'    => $payments->perPage(),
                ];
            }

            return response()->json([
                'status' => true,
                'data'   => $response
            ]);
        } catch (\Throwable $e) {

            Log::error($e);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
