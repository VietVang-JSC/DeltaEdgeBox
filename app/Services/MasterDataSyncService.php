<?php

namespace App\Services;

use App\Models\Table;
use App\Models\Product;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Printer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


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
            $times = array_filter([
                Table::max('updated_at'),
                Category::max('updated_at'),
                Product::max('updated_at'),
                Printer::max('updated_at'),
                PaymentMethod::max('updated_at'),
                \App\Models\ProductTimePrice::max('updated_at'),
                \App\Models\Store::max('updated_at'),
                \App\Models\User::max('updated_at'),
            ]);
            $lastSyncTime = !empty($times) ? \Illuminate\Support\Carbon::parse(max($times))->toIso8601String() : null;

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
                    Table::updateOrCreate(
                        ['id' => $table['id']],
                        [
                            'store_id'  => $table['store_id'],
                            'name'      => $table['tablename'], 
                            'status'    => $table['status'],
                            'admin_id'  => $table['admin_id'] ?? null,
                            'updated_at'=> $table['updated_at'] ?? now(),
                            'code'      => $table['tablename'],
                            'capacity'  => $table['number_of_people'] ?? 0,
                            'note'      => $table['listitem'] ?? null,
                        ]
                    );
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
                    \App\Models\Store::updateOrCreate(
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
                        \App\Models\User::where('email', $email)
                            ->where('id', '!=', $us['id'])
                            ->delete();

                        // updateOrCreate với cloud ID — an toàn hơn delete all + insert
                        \App\Models\User::updateOrCreate(
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

            // Log tổng kết sau mỗi sync
            $hasErrors = !empty($syncErrors);

            Log::info('Master sync completed', [
                'synced' => $syncResults,
                'errors' => $syncErrors,
            ]);

            return [
                'success' => !$hasErrors || !empty($syncResults),
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

        $existingByCode = Product::where('code', $code)->first();
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
        \App\Models\ProductTimePrice::where('product_id', $dbProductId)->delete();
        foreach ($product['time_prices'] ?? [] as $tp) {
            \App\Models\ProductTimePrice::create([
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
    }
}
