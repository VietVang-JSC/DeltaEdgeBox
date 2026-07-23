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
use App\Models\customers;
use App\Models\ComboProduct;
use App\Models\PaymentDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Agency;

class PosWebFilterController extends Controller
{
    public function searchProducts(Request $request)
    {
        try {
            $storeId = $this->storeId($request);
            $store = Store::find($storeId) ?: Store::first();
            $timezone = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');
            $isTaxIncluded = $store ? (int) ($store->is_tax_included ?? 0) : 0;

            $queryParam = $request->input('products.query', []);
            $productCodeQuery = $queryParam['product_code'] ?? [];
            $value = $productCodeQuery['value'] ?? '';

            $query = Product::query()
                ->with(['timePrices', 'types', 'product_types', 'combo_products', 'product_extras'])
                ->where(function ($q) use ($storeId) {
                    $q->whereNull('store_id')->orWhere('store_id', $storeId);
                })
                ->where('status', 1);

            if ($value !== '') {
                $query->where(function ($q) use ($value) {
                    $q->where('product_code', 'like', "%{$value}%")
                      ->orWhere('second_product_code', 'like', "%{$value}%")
                      ->orWhere('third_product_code', 'like', "%{$value}%")
                      ->orWhere('title', 'like', "%{$value}%")
                      ->orWhere('code', 'like', "%{$value}%")
                      ->orWhere('name', 'like', "%{$value}%");
                });
            }

            $products = $query->limit(100)
                ->get()
                ->map(fn (Product $product) => $this->productPayload($product, $timezone, $isTaxIncluded))
                ->values()
                ->all();

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'message' => 'Success',
                'data' => [
                    'data_product' => $products
                ]
            ]);
        } catch (\Throwable $exception) {
            Log::error('Edge product search failed', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'status_code' => 500,
                'message' => 'edge.product_search_failed',
            ], 500);
        }
    }

    private function defaultTimeZone(): string
    {
        return config('app.timezone');
    }

    public function filter(Request $request)
    {
        try {
            $storeId = $this->storeId($request);
            $user = $this->userPayload($request, $storeId);
            $products = $this->products($storeId, $request);
            $categories = $this->categories($storeId);
            $customers = $this->customers($storeId);
            $paymentsInput = $request->input('payments', []);
            if (!empty($paymentsInput)) {
                $payments = $this->filteredPayments($storeId, $paymentsInput);
            } else {
                $payments = $this->pendingPayments($storeId);
            }
            $tables = $this->tables($storeId);
            $store = $this->storePayload($storeId);
            $billSetting = $this->billSettingPayload($store);
            $bankPayment = $this->bankPaymentPayload();

            $dataAgencies = [];
            if ($request->has('agencies')) {
                $dataAgencies = $this->agencies($storeId, $request);
            }

            $dataCashDrawer = [];
            if ($request->has('cash_drawer')) {
                $dataCashDrawer = $this->cashDrawers($storeId, $request);
            }

            // Check if pagination is requested
            $pagination = $request->input('products.clauses.pagination');
            $dataProduct = $products;
            if ($pagination) {
                $pageSize = (int)data_get($pagination, 'pageSize', 50);
                $currentPage = (int)data_get($pagination, 'currentPage', 1);
                $totalProducts = \App\Models\Product::where(function ($q) use ($storeId) {
                    $q->whereNull('store_id')->orWhere('store_id', $storeId);
                })->where('status', 1)->count();
                $dataProduct = [
                    'data_list' => $products,
                    'total' => (int) ceil($totalProducts / $pageSize),
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
                    'data_attributes' => [],
                    'threshold_message' => [],
                    'data_stores' => [$store],
                    'data_bill_setting' => [$billSetting],
                    'data_bank_payment' => [$bankPayment],
                    'total_records_product' => count($products),
                    'data_agencies' => $dataAgencies,
                    'dataCashDrawer' => $dataCashDrawer,
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
        $storeId = $this->storeId(request());
        $bank = \App\Models\BankPayment::where('store_id', $storeId)->first();
        return $bank ? $bank->toArray() : [
            'bank_code' => '',
            'account_number' => '',
            'account_owner' => '',
        ];
    }

    private function products(int $storeId, Request $request = null): array
    {
        $store = Store::find($storeId) ?: Store::first();
        $timezone = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');
        $isTaxIncluded = $store ? (int) ($store->is_tax_included ?? 0) : 0;

        $query = Product::query()
            ->with(['timePrices', 'types', 'product_types', 'combo_products', 'product_extras'])
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->where('status', 1);

        if ($request) {
            $queryParam = $request->input('products.query', []);
            if (is_array($queryParam)) {
                foreach ($queryParam as $column => $value) {
                    if ($column === 'WhereRaw') {
                        continue; // Blocked: security risk (SQL injection)
                    } else {
                        if (is_array($value)) {
                            $operator = $value['operator'] ?? '=';
                            $val = $value['value'] ?? null;
                            if ($val !== null) {
                                if (strtolower($operator) === 'like') {
                                    $query->where($column, 'like', "%{$val}%");
                                } else {
                                    $query->where($column, $operator, $val);
                                }
                            }
                        } else {
                            if ($value !== null && $value !== '') {
                                $query->where($column, '=', $value);
                            }
                        }
                    }
                }
            }
        }

        // Apply pagination at DB level if requested
        $pagination = $request ? $request->input('products.clauses.pagination') : null;
        if ($pagination) {
            $pageSize = (int)data_get($pagination, 'pageSize', 50);
            $currentPage = (int)data_get($pagination, 'currentPage', 1);
            $query->limit($pageSize)->offset(($currentPage - 1) * $pageSize);
        } else {
            $query->limit(500);
        }

        return $query->get()
            ->map(fn (Product $product) => $this->productPayload($product, $timezone, $isTaxIncluded))
            ->values()
            ->all();
    }

    private function applyTimePrice(Product $product, string $timezone, &$availableFrames = []): array
    {
        $now = now()->setTimezone($timezone);
        $currentDay = $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        $timePrices = $product->timePrices ?? collect();

        // Build available frames for ALL matching day+time frames
        foreach ($timePrices as $tp) {
            if (empty($tp->is_active)) {
                continue;
            }
            $days = $tp->days_of_week;
            if (is_array($days) && in_array($currentDay, $days)) {
                $availableFrames[] = substr($tp->start_time, 0, 5) . '-' . substr($tp->end_time, 0, 5);
            }
        }

        // Find first matching time price (same as cloud: first-match-wins, no priority/date used)
        foreach ($timePrices as $tp) {
            if (empty($tp->is_active)) {
                continue;
            }

            $days = $tp->days_of_week;

            if (is_array($days) && in_array($currentDay, $days) && $currentTime >= $tp->start_time && $currentTime <= $tp->end_time) {
                $matchedPrice = $tp->price_after_tax ?? $tp->price ?? null;
                return [$product, $matchedPrice];
            }
        }

        return [$product, null];
    }

    private function productPayload(Product $product, string $timezone = null, ?int $isTaxIncluded = null): array
    {
        if ($timezone === null || $isTaxIncluded === null) {
            $store = Store::find($product->store_id) ?: Store::first();
            $timezone = $timezone ?? ($store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone'));
            $isTaxIncluded = $isTaxIncluded ?? ($store ? (int) ($store->is_tax_included ?? 0) : 0);
        }
        $availableFrames = [];
        [$product, $matchedPrice] = $this->applyTimePrice($product, $timezone, $availableFrames);

        $payload = $product->toArray();
        // Make image a full URL the browser can load from edge box
        if (!empty($payload['image'])) {
            if (str_starts_with($payload['image'], '/storage/')) {
                $payload['image'] = url($payload['image']);
            } elseif (!str_starts_with($payload['image'], 'http')) {
                $payload['image'] = url('storage/' . ltrim($payload['image'], '/'));
            }
        }
        $payload['product_code'] = $payload['product_code'] ?? $payload['code'] ?? (string) $product->id;
        $payload['title'] = $payload['title'] ?? $payload['name'] ?? '';
        $payload['price_after_tax'] = $matchedPrice !== null
            ? ($isTaxIncluded ? $matchedPrice : ($matchedPrice * (1 + ($product->vat ?? 0) / 100)))
            : ($product->price_after_tax ?? $product->price ?? 0);
        $payload['unit_price'] = $matchedPrice !== null ? $matchedPrice : ($product->price ?? 0);
        $payload['price'] = $matchedPrice !== null
            ? $matchedPrice
            : ($isTaxIncluded == 0 ? ($product->price ?? 0) : ($product->price_after_tax ?? 0));
        $payload['vat'] = $payload['vat'] ?? 0;
        $payload['tax_name'] = $this->taxName($payload['vat']);
        $payload['original_tax'] = $payload['vat'] < 0 ? $payload['vat'] : null;
        $payload['product_extra_list'] = $payload['product_extra_list'] ?? [];
        $payload['product_extras'] = $payload['product_extras'] ?? $payload['product_extra_list'];
        foreach ($payload['product_extras'] as &$extra) {
            if (isset($extra['product']) && is_array($extra['product'])) {
                $extra['product']['product_code'] = $extra['product']['code'] ?? ($extra['product']['product_code'] ?? '');
                $extra['product']['title'] = $extra['product']['title'] ?? ($extra['product']['name'] ?? '');
                $extra['product']['price_after_tax'] = $extra['product']['price_after_tax'] ?? ($extra['product']['price'] ?? 0);
                $extra['product']['inventory_required'] = (int) ($extra['product']['inventory_required'] ?? 0);
            }
        }
        unset($extra);
        $payload['combo_products'] = $payload['combo_products'] ?? [];
        foreach ($payload['combo_products'] as &$cp) {
            if (isset($cp['product']) && is_array($cp['product'])) {
                $cp['product']['product_code'] = $cp['product']['code'] ?? ($cp['product']['product_code'] ?? '');
                $cp['product']['title'] = $cp['product']['title'] ?? ($cp['product']['name'] ?? '');
            }
        }
        unset($cp);
        $payload['optional_products'] = $payload['optional_products'] ?? [];
        $payload['number_of_options'] = $payload['number_of_options'] ?? 0;
        if (isset($payload['types']) && is_array($payload['types'])) {
            $payload['types']['product_types'] = $payload['types']['product_types'] ?? [];
        } else {
            $payload['types'] = ['product_types' => []];
        }
        // Fallback: Types table may lack the record, but product_types (via product.type_id) has data
        if (empty($payload['types']['product_types']) && !empty($payload['product_types'])) {
            $payload['types']['product_types'] = $payload['product_types'];
        }
        $payload['time_prices'] = $payload['time_prices'] ?? [];
        $payload['product_time_prices'] = $payload['product_time_prices'] ?? $payload['time_prices'];
        $payload['is_restricted_time'] = $payload['is_restricted_time'] ?? 0;
        $payload['available_frames'] = $availableFrames;
        $payload['totalQuantity'] = $product->inventory ? $product->inventory->quantity : 0;
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
                $query->whereNull('store_id')
                    ->orWhere('store_id', $storeId);
            })
            ->get()
            ->map(fn (Payment $payment) => $this->paymentPayload($payment))
            ->values()
            ->all();
    }

    private function filteredPayments(int $storeId, array $paymentsInput): array
    {
        $query = $paymentsInput['query'] ?? [];
        $clauses = $paymentsInput['clauses'] ?? [];
        $orderBy = $clauses['orderby'] ?? ['column' => 'updated_at', 'value' => 'DESC'];
        $pagination = $clauses['pagination'] ?? null;

        $paymentsQuery = Payment::with(['user', 'customer', 'details'])
            ->where('store_id', $storeId);

        // Apply filters 
        foreach ($query as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                if (isset($value['condition'])) {
                    $condition = $value['condition'];
                    // Special handling for date fields with 'Between'
                    if (is_array($value['value']) && strtolower($condition) === 'between') {
                        $paymentsQuery->whereBetween(\Illuminate\Support\Facades\DB::raw("DATE($column)"), $value['value']);
                    } else {
                        $paymentsQuery->{"where{$condition}"}($column, $value['value']);
                    }
                } elseif (isset($value['operator'])) {
                    $operator = $value['operator'];
                    $val = $value['value'];
                    if (strtolower($operator) === 'like') {
                        $val = "%{$val}%";
                    }

                    if (isset($value['or'])) {
                        $paymentsQuery->where(function ($q) use ($column, $operator, $val, $value) {
                            $q->where($column, $operator, $val);
                            $orKeys = is_array($value['or']) ? $value['or'] : [$value['or']];
                            foreach ($orKeys as $orKey) {
                                $q->orWhere($orKey, $operator, $val);
                            }
                        });
                    } else {
                        if (strtolower($operator) === 'in' && is_array($val)) {
                            $paymentsQuery->whereIn($column, $val);
                        } else {
                            $paymentsQuery->where($column, $operator, $val);
                        }
                    }
                } elseif (isset($value[0]) && isset($value[1]) && $column === 'updated_at') {
                    // Fallback for old simple array of dates format without operator
                    $dates = array_filter($value);
                    if (!empty($dates)) {
                        $paymentsQuery->whereDate('updated_at', '>=', $dates[0]);
                        if (isset($dates[1])) {
                            $paymentsQuery->whereDate('updated_at', '<=', $dates[1]);
                        }
                    }
                } else {
                    $paymentsQuery->whereIn($column, $value);
                }
            } else {
                $paymentsQuery->where($column, $value);
            }
        }

        $column = $orderBy['column'] ?? 'updated_at';
        $direction = strtoupper($orderBy['value'] ?? 'DESC');
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'DESC';
        }

        if ($pagination) {
            $pageSize = (int) ($pagination['pageSize'] ?? 15);
            $currentPage = (int) ($pagination['currentPage'] ?? 1);
            $total = $paymentsQuery->count();
            $payments = $paymentsQuery->orderBy($column, $direction)
                ->skip(($currentPage - 1) * $pageSize)
                ->take($pageSize)
                ->get();
            $result = $payments->map(fn($p) => $this->paymentPayload($p))->values()->all();
            return [
                'data_list' => $result,
                'total' => (int) ceil($total / $pageSize),
                'pageSize' => $pageSize,
                'currentPage' => $currentPage,
            ];
        }

        return $paymentsQuery->orderBy($column, $direction)
            ->get()
            ->map(fn(Payment $payment) => $this->paymentPayload($payment))
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
        $payload['valuetotal'] = $payload['valuetotal'] ?? ($payload['final_total'] ?? ($payload['total'] ?? 0));
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

    private function agencies(int $storeId, Request $request): array
    {
        $query = Agency::query()
            ->where(function ($q) use ($storeId) {
                $q->whereNull('store_id')->orWhere('store_id', $storeId);
            });

        $agenciesParam = $request->input('agencies');
        if ($agenciesParam) {
            $whereQuery = data_get($agenciesParam, 'query');
            if ($whereQuery) {
                foreach ($whereQuery as $column => $cond) {
                    if (is_array($cond)) {
                        $operator = data_get($cond, 'operator', '=');
                        $value = data_get($cond, 'value');
                        $orColumn = data_get($cond, 'or');

                        if (strtolower($operator) === 'like') {
                            $value = '%' . $value . '%';
                        }

                        $query->where(function ($q) use ($column, $operator, $value, $orColumn) {
                            $q->where($column, $operator, $value);
                            if ($orColumn) {
                                $q->orWhere($column, $operator, $value);
                            }
                        });
                    } else {
                        $query->where($column, $cond);
                    }
                }
            }
        }

        $select = data_get($agenciesParam, 'select');
        if ($select && is_array($select)) {
            $query->select($select);
        }

        return $query->get()->toArray();
    }

    private function cashDrawers(int $storeId, Request $request): array
    {
        $query = \App\Models\CashDrawer::where('store_id', $storeId);

        $cdParam = $request->input('cash_drawer');
        if ($cdParam) {
            $whereQuery = data_get($cdParam, 'query');
            if ($whereQuery) {
                foreach ($whereQuery as $column => $cond) {
                    if (is_array($cond)) {
                        $operator = data_get($cond, 'operator', '=');
                        $value = data_get($cond, 'value');
                        if (strtolower($operator) === 'like') {
                            $value = '%' . $value . '%';
                        }
                        $query->where($column, $operator, $value);
                    } elseif ($column === 'WhereRaw') {
                        continue; // Blocked: security risk (SQL injection)
                    } elseif ($column !== 'store_id') {
                        $query->where($column, $cond);
                    }
                }
            }

            $clauses = data_get($cdParam, 'clauses');
            if ($clauses) {
                $orderBy = data_get($clauses, 'orderby');
                if ($orderBy && isset($orderBy['column'])) {
                    $query->orderBy($orderBy['column'], $orderBy['value'] ?? 'asc');
                }
            }
        }

        return $query->get()->toArray();
    }

    public function getCashDrawer(Request $request)
    {
        try {
            $storeId = $this->storeId($request);
            $today = now()->format('Y-m-d');
            $cashDrawer = \App\Models\CashDrawer::where('store_id', $storeId)
                ->where('status', 'open')
                ->whereRaw("started_at LIKE '%{$today}%'")
                ->orderBy('started_at', 'desc')
                ->first();

            if ($cashDrawer) {
                return response()->json(['status' => true, 'cashDrawer' => $cashDrawer->toArray()], 200);
            }

            // Fallback: find last closed drawer today
            $closedDrawer = \App\Models\CashDrawer::where('store_id', $storeId)
                ->where('status', 'closed')
                ->where('end_user_id', $request->input('user_id', 0))
                ->whereRaw("ended_at LIKE '%{$today}%'")
                ->orderBy('ended_at', 'desc')
                ->first();

            if ($closedDrawer) {
                return response()->json(['status' => true, 'cashDrawer' => $closedDrawer->toArray()], 200);
            }

            return response()->json(['status' => false, 'cashDrawer' => null], 200);
        } catch (\Throwable $th) {
            \Log::error('Edge getCashDrawer failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'cashDrawer' => null], 500);
        }
    }

    public function createCustomer(Request $request)
    {
        try {
            $storeId = $this->storeId($request);
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'name' => ['required'],
                'phone' => ['required'],
            ]);
            if ($validator->fails()) {
                return response()->json(['status' => false, 'status_code' => 400, 'message' => $validator->errors()], 400);
            }
            $customer = \App\Models\Customer::create([
                'store_id' => $storeId,
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'address' => $request->input('address', ''),
                'email' => $request->input('email', ''),
                'birthday' => $request->input('birthday'),
                'note' => $request->input('note', ''),
                'admin_id' => $request->input('admin_id', 0),
            ]);
            return response()->json([
                'status' => true,
                'message' => __('api.customer_created'),
                'data' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ],
                'customer' => $customer,
            ], 200);
        } catch (\Throwable $th) {
            \Log::error('Edge createCustomer failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => __('api.ISError')], 500);
        }
    }

    public function apiEdgeFilterByCondition(Request $request)
    {
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
            if (!empty($params['customers'])) {
                $customerQuery = Customer::query();

                $query = $params['customers']['query'] ?? [];

                foreach ($query as $column => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if (is_array($value)) {
                        $operator = $value['operator'] ?? '=';
                        $val = $value['value'] ?? null;

                        if ($val !== null) {
                            if (strtolower($operator) === 'like') {
                                $customerQuery->where($column, 'like', "%{$val}%");
                            } else {
                                $customerQuery->where($column, $operator, $val);
                            }
                        }
                    } else {
                        $customerQuery->where($column, $value);
                    }
                }

                // Optional relationships
                $relationships = $params['customers']['relationship'] ?? [];
                if (!empty($relationships)) {
                    $customerQuery->with($relationships);
                }

                // Order by
                if (!empty($params['customers']['clauses']['orderby'])) {
                    $orderBy = $params['customers']['clauses']['orderby'];
                    $customerQuery->orderBy(
                        $orderBy['column'] ?? 'id',
                        $orderBy['value'] ?? 'ASC'
                    );
                }

                // Pagination
                $pageSize = $params['customers']['clauses']['pagination']['pageSize'] ?? 15;
                $currentPage = $params['customers']['clauses']['pagination']['currentPage'] ?? 1;

                $customers = $customerQuery->paginate($pageSize, ['*'], 'page', $currentPage);

                $response['customer_list'] = [
                    'data_list' => $customers->items(),
                    'total' => $customers->total(),
                    'currentPage' => $customers->currentPage(),
                    'pageSize' => $customers->perPage(),
                ];
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

                // Relationship
                $relationships = $params['payments']['relationship'] ?? [];
                if (!empty($relationships)) {
                    $paymentQuery->with($relationships);
                }

                // Order by
                if (!empty($params['payments']['clauses']['orderby'])) {
                    $orderBy = $params['payments']['clauses']['orderby'];
                    $paymentQuery->orderBy(
                        $orderBy['column'] ?? 'updated_at',
                        $orderBy['value'] ?? 'DESC'
                    );
                }

                // Pagination
                $pageSize = $params['payments']['clauses']['pagination']['pageSize'] ?? 15;
                $currentPage = $params['payments']['clauses']['pagination']['currentPage'] ?? 1;

                $payments = $paymentQuery->paginate($pageSize, ['*'], 'page', $currentPage);

                $response['data_payment'] = [
                    'data_list' => $payments->items(),
                    'total' => $payments->total(),
                    'currentPage' => $payments->currentPage(),
                    'pageSize' => $payments->perPage(),
                ];
            }

            /**
             * DATA_PAYMENT (standalone key — client requests independently)
             */
            if (!empty($params['data_payment'])) {
                $paymentQuery = Payment::query();

                $query = $params['data_payment']['query'] ?? [];

                foreach ($query as $column => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if (is_array($value)) {
                        $operator = $value['operator'] ?? '=';
                        $val = $value['value'] ?? null;

                        if ($val !== null) {
                            if (strtolower($operator) === 'like') {
                                $paymentQuery->where($column, 'like', "%{$val}%");
                            } elseif (strtolower($operator) === 'in') {
                                $paymentQuery->whereIn($column, (array) $val);
                            } else {
                                $paymentQuery->where($column, $operator, $val);
                            }
                        }
                    } else {
                        $paymentQuery->where($column, $value);
                    }
                }

                // Optional relationships (e.g. ['details.product', 'customer', 'user'])
                $relationships = $params['data_payment']['relationship'] ?? [];
                if (!empty($relationships)) {
                    $paymentQuery->with($relationships);
                }

                // Order by
                if (!empty($params['data_payment']['clauses']['orderby'])) {
                    $orderBy = $params['data_payment']['clauses']['orderby'];
                    $paymentQuery->orderBy(
                        $orderBy['column'] ?? 'updated_at',
                        $orderBy['value'] ?? 'DESC'
                    );
                }

                // Pagination
                $pageSize = $params['data_payment']['clauses']['pagination']['pageSize'] ?? 15;
                $currentPage = $params['data_payment']['clauses']['pagination']['currentPage'] ?? 1;

                $payments = $paymentQuery->paginate($pageSize, ['*'], 'page', $currentPage);

                $response['data_payment'] = [
                    'data_list' => $payments->items(),
                    'total' => $payments->total(),
                    'currentPage' => $payments->currentPage(),
                    'pageSize' => $payments->perPage(),
                ];
            }

            /**
             * COMBO_PRODUCTS
             */
            if (array_key_exists('combo_products', $params)) {
                $comboQuery = ComboProduct::query();

                $query = $params['combo_products']['query'] ?? [];

                foreach ($query as $column => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if (is_array($value)) {
                        $operator = $value['operator'] ?? '=';
                        $val = $value['value'] ?? null;

                        if ($val !== null) {
                            if (strtolower($operator) === 'like') {
                                $comboQuery->where($column, 'like', "%{$val}%");
                            } elseif (strtolower($operator) === 'in') {
                                $comboQuery->whereIn($column, (array) $val);
                            } else {
                                $comboQuery->where($column, $operator, $val);
                            }
                        }
                    } else {
                        $comboQuery->where($column, $value);
                    }
                }

                // Optional relationships (e.g. ['products', 'comboProductDetails'])
                $relationships = $params['combo_products']['relationship'] ?? [];
                if (!empty($relationships)) {
                    $comboQuery->with($relationships);
                }

                // Order by
                if (!empty($params['combo_products']['clauses']['orderby'])) {
                    $orderBy = $params['combo_products']['clauses']['orderby'];
                    $comboQuery->orderBy(
                        $orderBy['column'] ?? 'id',
                        $orderBy['value'] ?? 'ASC'
                    );
                }

                // Pagination (optional — falls back to get() khi không truyền)
                $pagination = $params['combo_products']['clauses']['pagination'] ?? null;

                if ($pagination) {
                    $pageSize = (int) ($pagination['pageSize'] ?? 15);
                    $currentPage = (int) ($pagination['currentPage'] ?? 1);

                    $combos = $comboQuery->paginate($pageSize, ['*'], 'page', $currentPage);

                    $response['combo_products'] = [
                        'data_list' => $combos->items(),
                        'total' => $combos->total(),
                        'currentPage' => $combos->currentPage(),
                        'pageSize' => $combos->perPage(),
                    ];
                } else {
                    $response['combo_products'] = $comboQuery->get()->toArray();
                }
            }

            return response()->json([
                'status' => true,
                'data' => $response,
            ]);

        } catch (\Throwable $e) {
            Log::error($e);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getProductList(Request $request)
    {
        $storeId = config('edge_box.store_id') ?? \App\Models\Store::first()?->id ?? 1;
        $products = \App\Models\Product::with('timePrices', 'category', 'types', 'product_types', 'inventory', 'combo_products', 'product_extras')
            ->where('store_id', $storeId)->where('status', 1)->where('is_show', 1)->orderBy('sort_rank')->get();
        return response()->json([
            'status' => true,
            'data' => $products->map(fn($p) => $this->productPayload($p))->values()->all(),
        ]);
    }

    public function publicProductPayload(Product $product): array
    {
        return $this->productPayload($product);
    }

    public function getCategory(Request $request)
    {
        $storeId = config('edge_box.store_id') ?? \App\Models\Store::first()?->id ?? 1;
        $categories = \App\Models\Category::where('store_id', $storeId)->where('status', 1)->orderBy('sort_order')->get();
        return response()->json(['status' => true, 'data' => $categories]);
    }

    public function getAllCustomer(Request $request)
    {
        $storeId = config('edge_box.store_id') ?? \App\Models\Store::first()?->id ?? 1;
        $customers = \App\Models\Customer::where('store_id', $storeId)->orderBy('name')->get();
        return response()->json(['status' => true, 'data' => $customers]);
    }
}
