<?php

namespace App\Services;

use App\Models\Table;
use App\Models\Product;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Printer;
use App\Models\ProductTimePrice;
use App\Models\Store;
use App\Models\User;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Agency;
use App\Models\Types;
use App\Models\ProductType;
use App\Models\Payment;
use App\Models\PaymentDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MasterDataSyncService
{
    protected $cloudApiUrl;
    protected $apiKey;
    protected $storeId;

    public function __construct()
    {
        $this->cloudApiUrl = config('edge_box.cloud_api_url');
        $this->apiKey      = config('edge_box.api_key');
        $this->storeId     = config('edge_box.store_id');
    }

    /**
     * Sync master data  Cloud to EdgeBox
     */
    public function syncMasterData(): array
    {
        try {

            // 1. Tự động tính toán last_sync_time bằng cách lấy updated_at lớn nhất của các bảng cục bộ
            // Nếu có bất kỳ bảng master data quan trọng nào hoàn toàn rỗng, ta chạy full sync (lastSyncTime = null)
            // để nạp đầy đủ dữ liệu ban đầu cho bảng đó.
            $hasEmptyTable = Table::count() == 0
                || Category::count() == 0
                || Product::count() == 0
                || Printer::count() == 0
                || PaymentMethod::count() == 0
                || ProductTimePrice::count() == 0
                || Store::count() == 0
                || User::count() == 0
                || Inventory::count() == 0
                || Agency::count() == 0
                || Types::count() == 0
                || ProductType::count() == 0;

            if ($hasEmptyTable) {
                $lastSyncTime = null;
                Log::info('Master sync: Detected empty tables, forcing full sync to populate initial data.');
            } else {
                $times = array_filter([
                    Table::max('updated_at'),
                    Category::max('updated_at'),
                    Product::max('updated_at'),
                    Printer::max('updated_at'),
                    PaymentMethod::max('updated_at'),
                    ProductTimePrice::max('updated_at'),
                    Store::max('updated_at'),
                    User::max('updated_at'),
                    Inventory::max('updated_at'),
                    InventoryHistory::max('updated_at'),
                    Agency::max('updated_at'),
                    Types::max('updated_at'),
                    ProductType::max('updated_at'),
                ]);
                $lastSyncTime = !empty($times) ? Carbon::parse(max($times))->toIso8601String() : null;
            }

            /*
            | CALL CLOUD API
             */

            $response = Http::withHeaders([
                'X-Edge-Api-Key' => $this->apiKey,
                'Accept'        => 'application/json',
                'X-Store-ID'    => $this->storeId,
            ])
            ->timeout(15)
            ->post(
                rtrim($this->cloudApiUrl, '/') . '/api/edge-cloud/master-sync',
                [
                    'store_id' => $this->storeId,
                    'last_sync_time' => $lastSyncTime,
                    'sync_days' => 90,
                ]
            );

            /*
            | API FAILED
            */

            if (!$response->successful()) {

                return [
                    'success' => false,
                    'message' => 'Failed to fetch master data from cloud.',
                ];
            }

            $responseData = $response->json();

            // Nếu Cloud BE báo không có thay đổi nào mới hơn last_sync_time
            if (isset($responseData['has_changes']) && !$responseData['has_changes']) {
                return [
                    'success' => true,
                    'message' => 'Data is already up to date (no changes detected).',
                    'synced' => false
                ];
            }

            $data = $responseData['data'] ?? null;
            
            if (!$data) {

                return [
                    'success' => false,
                    'message' => 'Invalid response structure'
                ];
            }
            $syncResults = [];
            $syncErrors  = [];

            /*
             | TABLES — sync độc lập
            */
            try {
                DB::beginTransaction();
                foreach ($data['tables'] ?? [] as $table) {
                    $localTable = Table::find($table['id']);
                    $cloudUpdatedAt = Carbon::parse($table['updated_at']);

                    if (!$localTable || $cloudUpdatedAt->gte($localTable->updated_at)) {
                    Table::updateOrCreate(
                        ['id' => $table['id']],
                        [
                            'store_id'         => $table['store_id'],
                            'name'             => $table['tablename'] ?? ('Table-' . $table['id']),
                            'code'             => $table['code'] ?? ('TBL-' . $table['id']),
                            'tablename'        => $table['tablename'],
                            'status'           => $table['status'],
                            'admin_id'         => $table['admin_id'] ?? null,
                            'user_id'          => $table['user_id'] ?? null,
                            'payment_id'       => $table['payment_id'] ?? null,
                            'listitem'         => $table['listitem'] ?? null,
                            'userordered'      => $table['userordered'] ?? null,
                            'number_of_people' => $table['number_of_people'] ?? 0,
                            'can_order'        => $table['can_order'] ?? 1,
                            'is_order_enabled' => $table['is_order_enabled'] ?? 1,
                            'qr_token'         => $table['qr_token'] ?? null,
                            'qr_code'          => $table['qr_code'] ?? null,
                            'lock_time'        => $table['lock_time'] ?? null,
                            'pin'              => $table['pin'] ?? null,
                            'booking_code'     => $table['booking_code'] ?? null,
                            'updated_at'       => $table['updated_at'] ?? now(),
                        ]
                    );
                    } else {
                        Log::info("Master sync skipped table ID {$table['id']} because the local version is newer than the Cloud version.");
                    }
                }
                DB::commit();
                $syncResults['tables'] = count($data['tables'] ?? []);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync tables failed: ' . $e->getMessage());
                $syncErrors['tables'] = $e->getMessage();
            }

            /*
            | CATEGORIES — sync độc lập
            */
            try {
                DB::beginTransaction();
                foreach ($data['categories'] ?? [] as $category) {
                    Category::updateOrCreate(
                        ['id' => $category['id']],
                        [
                            'store_id'  => $category['store_id'],
                            'name'      => $category['category_name'], 
                            'status'    => $category['status'] ?? 1,
                            'admin_id'  => $category['admin_id'] ?? null,
                            'updated_at'=> $category['updated_at'] ?? now(),
                        ]
                    );
                }
                DB::commit();
                $syncResults['categories'] = count($data['categories'] ?? []);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync categories failed: ' . $e->getMessage());
                $syncErrors['categories'] = $e->getMessage();
            }

            /*
            | PRODUCTS — sync độc lập
            */
            try {
                DB::beginTransaction();
                foreach ($data['products'] ?? [] as $product) {
                    $this->upsertProduct($product);
                }
                DB::commit();
                $syncResults['products'] = count($data['products'] ?? []);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync products failed: ' . $e->getMessage());
                $syncErrors['products'] = $e->getMessage();
            }

            /*
            | PRINTERS — sync độc lập
            */
            try {
                DB::beginTransaction();
                foreach ($data['printers'] ?? [] as $printer) {
                    $active = (bool) ($printer['active'] ?? true);
                    Printer::updateOrCreate(
                        ['id' => $printer['id']],
                        [
                            'store_id' => $printer['store_id'] ?? $this->storeId,
                            'name' => $printer['name'] ?? null,
                            'printer_type' => $printer['printer_type'] ?? 'kitchen',
                            'connection_type' => $printer['connection_type'] ?? 'network',
                            'ip_address' => $printer['ip_address'] ?? null,
                            'port' => $printer['port'] ?? 9100,
                            'device_path' => $printer['device_path'] ?? null,
                            'active' => $active,
                            'default' => $printer['default'] ?? null,
                            'paper_size' => $printer['paper_size'] ?? '58',
                            'is_active' => $printer['is_active'] ?? $active,
                            'status' => $printer['status'] ?? ($active ? 'online' : 'offline'),
                            'last_status_check' => $printer['last_status_check'] ?? null,
                            'created_at' => $printer['created_at'] ?? now(),
                            'updated_at' => $printer['updated_at'] ?? now(),
                        ]
                    );
                }
                DB::commit();
                $syncResults['printers'] = count($data['printers'] ?? []);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync printers failed: ' . $e->getMessage());
                $syncErrors['printers'] = $e->getMessage();
            }

            /*
            | PAYMENT METHODS — sync độc lập
            */
            try {
                DB::beginTransaction();
                foreach ($data['payment_methods'] ?? [] as $paymentMethod) {
                    PaymentMethod::withTrashed()->updateOrCreate(
                        [
                            'store_id' => $paymentMethod['store_id'] ?? $this->storeId,
                            'value' => $paymentMethod['value'],
                        ],
                        [
                            'name' => $paymentMethod['name'],
                            'created_at' => $paymentMethod['created_at'] ?? now(),
                            'updated_at' => $paymentMethod['updated_at'] ?? now(),
                            'deleted_at' => $paymentMethod['deleted_at'] ?? null,
                        ]
                    );
                }
                DB::commit();
                $syncResults['payment_methods'] = count($data['payment_methods'] ?? []);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync payment_methods failed: ' . $e->getMessage());
                $syncErrors['payment_methods'] = $e->getMessage();
            }

            /*
            | STORE — sync độc lập
            */
            try {
                if (!empty($data['store'])) {
                    DB::beginTransaction();
                    $st = $data['store'];
                    // Cleanup: remove any store with wrong ID that blocks the unique code
                    Store::where('id', '!=', $st['id'])->where('code', 'STORE-' . $st['id'])->delete();
                    Store::updateOrCreate(
                        ['id' => $st['id']],
                        [
                            'service_level_id' => $st['service_level_id'] ?? null,
                            'name' => $st['name'] ?? null,
                            'storename' => $st['storename'] ?? null,
                            'address' => $st['address'] ?? null,
                            'province' => $st['province'] ?? null,
                            'phone' => $st['phone'] ?? null,
                            'email' => $st['email'] ?? null,
                            'referer_phone' => $st['referer_phone'] ?? null,
                            'note' => $st['note'] ?? null,
                            'currency' => $st['currency'] ?? 'VND',
                            'expiry_date' => $st['expiry_date'] ?? null,
                            'industry_id' => $st['industry_id'] ?? null,
                            'company_id' => $st['company_id'] ?? null,
                            'parent_id' => $st['parent_id'] ?? null,
                            'is_headquarters' => $st['is_headquarters'] ?? false,
                            'line_user_id' => $st['line_user_id'] ?? null,
                            'api_key' => $st['api_key'] ?? null,
                            'is_tax_included' => $st['is_tax_included'] ?? false,
                            'printer_host' => $st['printer_host'] ?? null,
                            'time_zone' => $st['time_zone'] ?? 'Asia/Ho_Chi_Minh',
                            'use_node_print_driver' => $st['use_node_print_driver'] ?? true,
                            'type_check_qr' => $st['type_check_qr'] ?? 'pin',
                            'setting_print_kitchen' => isset($st['setting_print_kitchen']) 
                                ? (is_array($st['setting_print_kitchen']) ? $st['setting_print_kitchen'] : json_decode($st['setting_print_kitchen'], true)) 
                                : null,
                            'current_ip' => $st['current_ip'] ?? null,
                            'service_charge' => $st['service_charge'] ?? null,
                            'deployment_mode' => $st['deployment_mode'] ?? 'cloud-only',
                            'edge_routing_active' => $st['edge_routing_active'] ?? false,
                            'edge_box_url' => $st['edge_box_url'] ?? null,
                            'edge_box_store_id' => $st['edge_box_store_id'] ?? null,
                            'edge_box_api_key' => $st['edge_box_api_key'] ?? null,
                            'edge_enabled_at' => $st['edge_enabled_at'] ?? null,
                            'edge_config_version' => $st['edge_config_version'] ?? 1,
                            'status' => $st['status'] ?? true,
                            'code' => $st['code'] ?? ('STORE-' . $st['id']),
                            'created_at' => $st['created_at'] ?? now(),
                            'updated_at' => $st['updated_at'] ?? now(),
                        ]
                    );
                    DB::commit();
                    $syncResults['stores'] = 1;
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync store failed: ' . $e->getMessage());
                $syncErrors['store'] = $e->getMessage();
            }

            /*
            | USERS — sync độc lập, xử lý UNIQUE email constraint
            | Chiến lược: Xóa tất cả users cũ rồi insert lại từ Cloud
            | để tránh conflict ID mapping và email UNIQUE
            */
            try {
                if (!empty($data['users'])) {
                    DB::beginTransaction();

                    foreach ($data['users'] as $us) {
                        // Tạo email unique bằng cách thêm cloud_id prefix
                        $email = !empty($us['email']) 
                            ? $us['id'] . '_' . $us['email'] 
                            : $us['id'] . '_noemail@deltapos.vn';

                        // Xóa row conflict: nếu email này đã tồn tại ở user khác (ID khác)
                        // thì xóa row cũ trước để tránh UNIQUE constraint violation
                        User::where('email', $email)
                            ->where('id', '!=', $us['id'])
                            ->delete();

                        // updateOrCreate với cloud ID — an toàn hơn delete all + insert
                        User::updateOrCreate(
                            ['id' => $us['id']],
                            [
                                'store_id'   => $us['store_id'] ?? null,
                                'name'       => $us['name'] ?? null,
                                'email'      => $email,
                                'password'   => $us['password'] ?? bcrypt('123123'),
                                'role'       => $us['role'] ?? 2,
                                'status'     => $us['status'] ?? true,
                                'phone'      => $us['phone'] ?? null,
                                'created_at' => $us['created_at'] ?? now(),
                                'updated_at' => $us['updated_at'] ?? now(),
                            ]
                        );
                    }

                    DB::commit();
                    $syncResults['users'] = count($data['users']);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync users failed: ' . $e->getMessage());
                $syncErrors['users'] = $e->getMessage();
            }

            // Tạo bản đồ ánh xạ từ product_id trên Cloud sang product_id thực tế ở Edge SQLite
            // Tối ưu hóa hiệu năng: dùng 1 câu query pluck duy nhất thay vì N câu query tuần tự (N+1 query)
            $codes = array_column($data['products'] ?? [], 'product_code');
            $localProducts = Product::whereIn('code', $codes)
                ->where('store_id', $this->storeId)
                ->pluck('id', 'code'); // ['SP001' => 5, 'SP002' => 8, ...]

            $productMap = [];
            foreach ($data['products'] ?? [] as $prod) {
                $localId = $localProducts[$prod['product_code']] ?? null;
                if ($localId) {
                    $productMap[$prod['id']] = $localId;
                }
            }

            // Sync INVENTORIES
            try {
                if (isset($data['inventories'])) {
                    DB::beginTransaction();
                    foreach ($data['inventories'] as $inv) {
                        $localProductId = $productMap[$inv['product_id']] ?? $inv['product_id'];

                        // Kiểm tra sản phẩm có thực tế tồn tại trong SQLite cục bộ không để tránh vi phạm khóa ngoại
                        if (Product::where('id', $localProductId)->exists()) {
                            Inventory::updateOrCreate(
                                [
                                    'store_id' => $inv['store_id'],
                                    'product_id' => $localProductId,
                                ],
                                [
                                    'quantity' => $inv['quantity'],
                                    'admin_id' => $inv['admin_id'] ?? null,
                                    'updated_at' => $inv['updated_at'] ?? now(),
                                ]
                            );
                        } else {
                            Log::warning("Sync inventories: Product ID {$inv['product_id']} (Local ID {$localProductId}) does not exist in local DB. Skipped.");
                        }
                    }
                    DB::commit();
                    $syncResults['inventories'] = count($data['inventories']);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync inventories failed: ' . $e->getMessage());
                $syncErrors['inventories'] = $e->getMessage();
            }

            // Sync INVENTORY_HISTORIES
            try {
                if (isset($data['inventory_histories'])) {
                    DB::beginTransaction();
                    
                    // Clean up synced local records to prevent duplicates before applying cloud updates
                    $syncedInputCodes = array_unique(array_column($data['inventory_histories'], 'input_code'));
                    if (!empty($syncedInputCodes)) {
                        InventoryHistory::whereIn('input_code', $syncedInputCodes)->delete();
                    }

                    foreach ($data['inventory_histories'] as $ih) {
                        $localProductId = $productMap[$ih['product_id']] ?? $ih['product_id'];

                        // Kiểm tra sản phẩm có thực tế tồn tại trong SQLite cục bộ không để tránh vi phạm khóa ngoại
                        if (Product::where('id', $localProductId)->exists()) {
                            InventoryHistory::updateOrCreate(
                                ['id' => $ih['id']],
                                [
                                    'store_id' => $ih['store_id'],
                                    'product_id' => $localProductId,
                                    'input_id' => $ih['input_id'],
                                    'input_code' => $ih['input_code'],
                                    'input_date' => $ih['input_date'],
                                    'quantity' => $ih['quantity'],
                                    'admin_id' => $ih['admin_id'] ?? null,
                                    'updated_at' => $ih['updated_at'] ?? now(),
                                ]
                            );
                        } else {
                            Log::warning("Sync inventory_histories: Product ID {$ih['product_id']} (Local ID {$localProductId}) does not exist in local DB. Skipped.");
                        }
                    }
                    DB::commit();
                    $syncResults['inventory_histories'] = count($data['inventory_histories']);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync inventory_histories failed: ' . $e->getMessage());
                $syncErrors['inventory_histories'] = $e->getMessage();
            }

            // Sync AGENCIES
            try {
                if (isset($data['agencies'])) {
                    DB::beginTransaction();
                    Log::info('Master sync: Start syncing agencies. Count from cloud: ' . count($data['agencies']));
                    foreach ($data['agencies'] as $agency) {
                        Agency::withTrashed()->updateOrCreate(
                            ['id' => $agency['id']],
                            [
                                'store_id'       => $agency['store_id'] ?? $this->storeId,
                                'code'           => $agency['code'],
                                'name'           => $agency['name'],
                                'contact_person' => $agency['contact_person'] ?? null,
                                'contact_email'  => $agency['contact_email'] ?? null,
                                'contact_number' => $agency['contact_number'] ?? null,
                                'company_name'   => $agency['company_name'] ?? null,
                                'company_tax'    => $agency['company_tax'] ?? null,
                                'address'        => $agency['address'] ?? null,
                                'note'           => $agency['note'] ?? null,
                                'user_init'      => $agency['user_init'] ?? null,
                                'user_upd'       => $agency['user_upd'] ?? null,
                                'admin_id'       => $agency['admin_id'] ?? null,
                                'created_at'     => $agency['created_at'] ?? now(),
                                'updated_at'     => $agency['updated_at'] ?? now(),
                                'deleted_at'     => $agency['deleted_at'] ?? null,
                            ]
                        );
                    }
                    DB::commit();
                    $syncResults['agencies'] = count($data['agencies']);
                    Log::info('Sync agencies completed successfully.', ['count' => count($data['agencies'])]);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync agencies failed: ' . $e->getMessage());
                $syncErrors['agencies'] = $e->getMessage();
            }

            // Sync TYPES
            try {
                if (isset($data['types'])) {
                    DB::beginTransaction();
                    foreach ($data['types'] as $type) {
                        Types::updateOrCreate(
                            ['id' => $type['id']],
                            [
                                'store_id'          => $type['store_id'],
                                'product_type_name' => $type['product_type_name'],
                                'admin_id'          => $type['admin_id'],
                                'created_at'        => $type['created_at'] ?? now(),
                                'updated_at'        => $type['updated_at'] ?? now(),
                            ]
                        );
                    }
                    DB::commit();
                    $syncResults['types'] = count($data['types']);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync types failed: ' . $e->getMessage());
                $syncErrors['types'] = $e->getMessage();
            }

            // Sync PRODUCT_TYPES
            try {
                if (isset($data['product_types'])) {
                    DB::beginTransaction();
                    foreach ($data['product_types'] as $pt) {
                        ProductType::updateOrCreate(
                            ['id' => $pt['id']],
                            [
                                'store_id'                     => $pt['store_id'],
                                'product_type_id'              => $pt['product_type_id'],
                                'product_type_attribute'       => $pt['product_type_attribute'],
                                'product_type_attribute_value' => $pt['product_type_attribute_value'],
                                'admin_id'                     => $pt['admin_id'],
                                'created_at'                   => $pt['created_at'] ?? now(),
                                'updated_at'                   => $pt['updated_at'] ?? now(),
                            ]
                        );
                    }
                    DB::commit();
                    $syncResults['product_types'] = count($data['product_types']);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync product_types failed: ' . $e->getMessage());
                $syncErrors['product_types'] = $e->getMessage();
            }

            // Sync PAYMENTS
            // Build product ID map (cloud ID → local ID) for payment_details FK
            $paymentProductCodes = [];
            foreach ($data['payments'] ?? [] as $pmt) {
                $details = $pmt['payment_details'] ?? ($pmt['paymentDetails'] ?? []);
                foreach ($details as $pd) {
                    $code = $pd['product_code'] ?? '';
                    if (empty($code) && !empty($pd['product_key'])) {
                        $code = explode('.', $pd['product_key'])[0] ?? '';
                    }
                    if (!empty($code)) { $paymentProductCodes[] = $code; }
                }
            }
            $paymentLocalProducts = !empty($paymentProductCodes)
                ? Product::whereIn('code', array_unique($paymentProductCodes))
                    ->where('store_id', $this->storeId)
                    ->pluck('id', 'code')
                    ->toArray()
                : [];
            try {
                if (isset($data['payments'])) {
                    DB::beginTransaction();
                    $syncedCount = 0;
                    foreach ($data['payments'] as $pmt) {
                        $paymentId = $pmt['id'];
                        $paymentDetails = $pmt['payment_details'] ?? ($pmt['paymentDetails'] ?? []);

                        Payment::updateOrCreate(
                            ['id' => $paymentId],
                            [
                                'paid_date'                    => $pmt['paid_date'] ?? $pmt['created_at'] ?? now(),
                                'store_id'                     => $pmt['store_id'],
                                'table_id'                     => $pmt['table_id'] ?? null,
                                'customer_id'                  => $pmt['customer_id'] ?? 0,
                                'user_id'                      => $pmt['user_id'] ?? 0,
                                'admin_id'                     => $pmt['admin_id'] ?? 0,
                                'payment_code'                 => $pmt['payment_code'] ?? ('PMT-' . $paymentId),
                                'reason'                       => $pmt['reason'] ?? '',
                                'items'                        => $pmt['items'] ?? '',
                                'total'                        => $pmt['valuetotal'] ?? $pmt['total'] ?? 0,
                                'final_total'                  => $pmt['valuetotal'] ?? $pmt['final_total'] ?? 0,
                                'discount'                     => $pmt['discount'] ?? 0,
                                'surcharge'                    => $pmt['surcharge'] ?? 0,
                                'surcharge_reason'             => $pmt['surcharge_reason'] ?? $pmt['reasonSurcharge'] ?? '',
                                'surcharge_percent'            => $pmt['surcharge_percent'] ?? 0,
                                'service_charge'               => $pmt['service_charge'] ?? 0,
                                'service_charge_amount'        => $pmt['service_charge_amount'] ?? 0,
                                'tax'                          => $pmt['total_tax'] ?? $pmt['tax'] ?? 0,
                                'payment_method'               => $pmt['payment_method'] ?? '',
                                'status'                       => $pmt['status'] ?? 1,
                                'type_discount'                => $pmt['type_discount'] ?? 'amount',
                                'discount_percent'             => $pmt['discount_percent'] ?? 0,
                                'is_senior_discount'           => $pmt['is_senior_discount'] ?? 0,
                                'senior_discount_amount'       => $pmt['senior_discount_amount'] ?? 0,
                                'parent_id'                    => $pmt['parent_id'] ?? null,
                                'sub_total_before_discount'    => $pmt['sub_total_before_discount'] ?? $pmt['sub_total'] ?? 0,
                                'total_incl_vat_before_discount' => $pmt['total_incl_vat_before_discount'] ?? 0,
                                'created_at'                   => $pmt['created_at'] ?? now(),
                                'updated_at'                   => $pmt['updated_at'] ?? now(),
                            ]
                        );

                        // Sync payment details
                        if (!empty($paymentDetails)) {
                            PaymentDetail::where('payment_id', $paymentId)->delete();
                            foreach ($paymentDetails as $pd) {
                                $pdCode = $pd['product_code'] ?? '';
                                if (empty($pdCode) && !empty($pd['product_key'])) {
                                    $pdCode = explode('.', $pd['product_key'])[0] ?? '';
                                }
                                $localProductId = $paymentLocalProducts[$pdCode] ?? null;
                                PaymentDetail::create([
                                    'payment_id'                     => $paymentId,
                                    'product_id'                     => $localProductId ?: 0,
                                    'product_key'                    => $pd['product_key'] ?? $pd['product_code'] ?? '',
                                    'quantity'                       => $pd['quantity'] ?? 1,
                                    'price'                          => $pd['price'] ?? 0,
                                    'total'                          => $pd['total'] ?? ($pd['price'] ?? 0) * ($pd['quantity'] ?? 1),
                                    'note'                           => $pd['note'] ?? '',
                                    'detail_discount'                => $pd['detail_discount'] ?? 0,
                                    'tax_amount'                     => $pd['tax_amount'] ?? 0,
                                    'detail_discount_excluding_tax'  => $pd['detail_discount_excluding_tax'] ?? 0,
                                    'unit_price_excluding_tax'       => $pd['unit_price_excluding_tax'] ?? 0,
                                    'discounted_price_excluding_tax' => $pd['discounted_price_excluding_tax'] ?? 0,
                                    'store_id'                       => $pmt['store_id'],
                                    'admin_id'                       => $pmt['admin_id'] ?? 0,
                                ]);
                            }
                        }
                        $syncedCount++;
                    }
                    DB::commit();
                    $syncResults['payments'] = $syncedCount;
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Sync payments failed: ' . $e->getMessage());
                $syncErrors['payments'] = $e->getMessage();
            }

            // Log tổng kết sau mỗi sync
            $hasErrors = !empty($syncErrors);

            Log::info('Master sync completed', [
                'synced' => $syncResults,
                'errors' => $syncErrors,
            ]);

            return [
                'success' => !$hasErrors || !empty($syncResults),
                'synced' => true,
                'message' => $hasErrors 
                    ? 'Master sync completed with some errors.' 
                    : 'Master data synced successfully.',
                'data' => $syncResults,
                'errors' => $hasErrors ? $syncErrors : null,
            ];

        } catch (\Exception $e) {

            Log::error('Master sync critical error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Master sync failed.',
                'error'   => $e->getMessage(),
            ];
        }
    }

    private function upsertProduct(array $product): void
    {
        $code = $product['product_code'];
        $payload = [
            'category_id' => $product['category_id'],
            'store_id'    => $product['store_id'],
            'name'        => $product['title'],
            'code'        => $code,
            'sku'         => $code,
            'price'       => $product['price'],
            'status'      => $product['status'],
            'quantity'    => $product['quantity'] ?? ($product['inventory']['quantity'] ?? 0),
            'admin_id'    => $product['admin_id'] ?? null,
            'is_restricted_time' => $product['is_restricted_time'] ?? 0,
            
            // Sync all new Cloud attributes
            'vat'                 => $product['vat'] ?? 0,
            'price_after_tax'     => $product['price_after_tax'] ?? $product['price'] ?? 0,
            'min_quantity'        => $product['min_quantity'] ?? 0,
            'product_group_id'    => $product['product_group_id'] ?? null,
            'type_final_product'  => $product['type_final_product'] ?? null,
            'type_commodity'      => $product['type_commodity'] ?? null,
            'is_extra'            => $product['is_extra'] ?? 0,
            'check'               => $product['check'] ?? null,
            'second_product_code' => $product['second_product_code'] ?? null,
            'third_product_code'  => $product['third_product_code'] ?? null,
            'type_id'             => $product['type_id'] ?? null,
            'inventory_required'  => $product['inventory_required'] ?? 0,
            'number_of_options'   => $product['number_of_options'] ?? 0,
            'is_ingredient'       => $product['is_ingredient'] ?? 0,
            'print_id'            => $product['print_id'] ?? null,
            'sort_rank'           => $product['sort_rank'] ?? 0,
            'is_show'             => $product['is_show'] ?? 1,
            'title_vi'            => $product['title_vi'] ?? null,
            
            'updated_at'  => $product['updated_at'] ?? now(),
        ];

        $existingByCode = Product::where('store_id', $product['store_id'])
                                ->where('code', $code)
                                ->first();
        if ($existingByCode && (int) $existingByCode->id !== (int) $product['id']) {
            $existingByCode->update($payload);

            Log::warning('Master sync product id conflict resolved by code', [
                'cloud_id' => $product['id'],
                'edge_id' => $existingByCode->id,
                'code' => $code,
            ]);

            $dbProductId = $existingByCode->id;
        } else {
            $dbProduct = Product::updateOrCreate(
                [
                    'id' => $product['id'],
                ],
                array_merge($payload, [
                    'created_at' => $product['created_at'] ?? now(),
                ])
            );
            $dbProductId = $dbProduct->id;
        }

        // Đồng bộ các khung giờ giá của sản phẩm này
        // Bọc trong transaction cục bộ để tăng tính an toàn và đảm bảo tính nguyên tử (atomic)
        DB::transaction(function () use ($product, $dbProductId) {
            ProductTimePrice::where('product_id', $dbProductId)->delete();
            foreach ($product['time_prices'] ?? [] as $tp) {
                // Xoá bản ghi trùng ID nếu có (tránh lỗi UNIQUE constraint failed khi ID bị lệch sở hữu hoặc đồng bộ ngắt quãng)
                ProductTimePrice::where('id', $tp['id'])->delete();
                ProductTimePrice::create([
                    'id' => $tp['id'],
                    'product_id' => $dbProductId,
                    'store_id' => $tp['store_id'],
                    'start_time' => $tp['start_time'],
                    'end_time' => $tp['end_time'],
                    'price' => $tp['price'],
                    'price_after_tax' => $tp['price_after_tax'] ?? $tp['price'],
                    'priority' => $tp['priority'] ?? 0,
                    'days_of_week_mask' => isset($tp['days_of_week_mask']) ? $tp['days_of_week_mask'] : (function() use ($tp) {
                        $days = $tp['days_of_week'] ?? null;
                        if ($days === null || count($days) === 7) {
                            return null;
                        }
                        $mask = 0;
                        foreach ($days as $day) {
                            $mask |= (1 << (int) $day);
                        }
                        return $mask;
                    })(),
                    'start_date' => $tp['start_date'] ?? null,
                    'end_date' => $tp['end_date'] ?? null,
                    'is_active' => $tp['is_active'] ?? 1,
                    'created_at' => $tp['created_at'] ?? now(),
                    'updated_at' => $tp['updated_at'] ?? now(),
                ]);
            }
        });
    }
}
