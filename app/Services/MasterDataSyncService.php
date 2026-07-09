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
use App\Models\ProductExtra;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Customer;
use App\Models\CashDrawer;
use App\Models\Booking;
use App\Models\SyncMetadata;
use App\Models\BankPayment;
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
    public function syncMasterData(bool $force = false): array
    {
        try {

            // Khi Edge thiáº¿u dá»¯ liá»‡u core hoáº·c admin yÃªu cáº§u force, cháº¡y full sync Ä‘á»ƒ bootstrap láº¡i SQLite.
            $forceFullSync = $force || $this->hasMissingCoreTables();
            $lastSyncTimes = $forceFullSync ? [] : $this->buildPerTableSyncTimes();
            // Normal sync: chá»‰ sync master data, ko ghi Ä‘Ã¨ transaction (inventory, payments...)
            $skipTransactional = !$forceFullSync;

            if ($forceFullSync) {
                Log::info('Master sync: force sync: syncing all data including transactions.');
            } else {
                Log::info('Master sync: normal sync: skipping transactional data (inventory, payments) to preserve local box data.');
            }

            /*
            | CALL CLOUD API
             */

            $response = Http::withHeaders([
                'X-Edge-Api-Key' => $this->apiKey,
                'Accept'        => 'application/json',
                'X-Store-ID'    => $this->storeId,
            ])
            ->timeout(60)
            ->post(
                rtrim($this->cloudApiUrl, '/') . '/api/edge-cloud/master-sync',
                [
                    'store_id' => $this->storeId,
                    'last_sync_times' => $lastSyncTimes,
                    'last_sync_time' => null,
                    'force' => $forceFullSync,
                    'sync_days' => 90,
                ]
            );

            /*
            | API FAILED
            */

            if (!$response->successful()) {
                $this->markSyncMetadata('error', 'Failed to fetch master data from cloud.');

                return [
                    'success' => false,
                    'message' => 'Failed to fetch master data from cloud.',
                ];
            }

            $responseData = $response->json();

            // Náº¿u Cloud BE bÃ¡o khÃ´ng cÃ³ thay Ä‘á»•i nÃ o má»›i hÆ¡n last_sync_time
            if (isset($responseData['has_changes']) && !$responseData['has_changes']) {
                $this->markSyncMetadata('idle');

                return [
                    'success' => true,
                    'message' => 'Data is already up to date (no changes detected).',
                    'synced' => false
                ];
            }

            $data = $responseData['data'] ?? null;
            
            if (!$data) {
                $this->markSyncMetadata('error', 'Invalid response structure');

                return [
                    'success' => false,
                    'message' => 'Invalid response structure'
                ];
            }
            $syncResults = [];
            $syncErrors  = [];

            /*
             | TABLES â€” sync Ä‘á»™c láº­p
            */
            try {
                DB::beginTransaction();
                Table::withoutEvents(function () use ($data) {
                    foreach ($data['tables'] ?? [] as $table) {
                        $localTable = Table::where('store_id', $this->storeId)->find($table['id']);

                        $masterPayload = [
                            'store_id'         => $table['store_id'],
                            'tablename'        => $table['tablename'],
                            'admin_id'         => $table['admin_id'] ?? null,
                            'can_order'        => $table['can_order'] ?? 1,
                            'is_order_enabled' => $table['is_order_enabled'] ?? 1,
                            'qr_token'         => $table['qr_token'] ?? null,
                            'qr_code'          => $table['qr_code'] ?? null,
                            'pin'              => $table['pin'] ?? null,
                            'booking_code'     => $table['booking_code'] ?? null,
                            'updated_at'       => $table['updated_at'] ?? now(),
                        ];

                        if ($localTable) {
                            $localTable->update($masterPayload);
                        } else {
                            Table::create(array_merge(
                                ['id' => $table['id']],
                                $masterPayload,
                                [
                                    'status'           => $table['status'] ?? 1,
                                    'user_id'          => $table['user_id'] ?? null,
                                    'payment_id'       => $table['payment_id'] ?? null,
                                    'listitem'         => $table['listitem'] ?? null,
                                    'userordered'      => $table['userordered'] ?? null,
                                    'number_of_people' => $table['number_of_people'] ?? 0,
                                    'lock_time'        => $table['lock_time'] ?? null,
                                    'created_at'       => $table['created_at'] ?? now(),
                                ]
                            ));
                        }
                    }
                });
                DB::commit();
                $syncResults['tables'] = count($data['tables'] ?? []);
            } catch (\Exception $e) {
                $this->rollbackIfNeeded();
                Log::error('Sync tables failed: ' . $e->getMessage());
                $syncErrors['tables'] = $e->getMessage();
            }

            /*
            | CATEGORIES â€” sync Ä‘á»™c láº­p
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
                $this->rollbackIfNeeded();
                Log::error('Sync categories failed: ' . $e->getMessage());
                $syncErrors['categories'] = $e->getMessage();
            }

            /*
            | PRODUCTS — sync độc lập
            */
            try {
                DB::statement('PRAGMA foreign_keys = OFF');
                DB::beginTransaction();
                foreach ($data['products'] ?? [] as $product) {
                    $this->upsertProduct($product);
                }
                DB::commit();
                DB::statement('PRAGMA foreign_keys = ON');
                $syncResults['products'] = count($data['products'] ?? []);
            } catch (\Exception $e) {
                $this->rollbackIfNeeded();
                Log::error('Sync products failed: ' . $e->getMessage());
                $syncErrors['products'] = $e->getMessage();
            }

            /*
            | PRINTERS â€” sync Ä‘á»™c láº­p
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
                $this->rollbackIfNeeded();
                Log::error('Sync printers failed: ' . $e->getMessage());
                $syncErrors['printers'] = $e->getMessage();
            }

            /*
            | PAYMENT METHODS â€” sync Ä‘á»™c láº­p
            */
            try {
                DB::beginTransaction();
                foreach ($data['payment_methods'] ?? [] as $paymentMethod) {
                    PaymentMethod::withTrashed()->updateOrCreate(
                        [
                            'store_id' => !empty($paymentMethod['store_id']) ? $paymentMethod['store_id'] : $this->storeId,
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
                $this->rollbackIfNeeded();
                Log::error('Sync payment_methods failed: ' . $e->getMessage());
                $syncErrors['payment_methods'] = $e->getMessage();
            }

            /*
            | STORE â€” sync Ä‘á»™c láº­p
            */
            try {
                if (!empty($data['store'])) {
                    DB::beginTransaction();
                    $st = $data['store'];
                    // Free up the code for the correct store (avoid FK cascade by update not delete)
                    Store::where('code', 'STORE-' . $st['id'])->where('id', '!=', $st['id'])->update(['code' => 'STALE-' . $st['id'] . '-' . time()]);
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
                            'time_zone' => $st['time_zone'] ?? 'Asia/Manila',
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
                $this->rollbackIfNeeded();
                Log::error('Sync store failed: ' . $e->getMessage());
                $syncErrors['store'] = $e->getMessage();
            }

            /*
            | USERS â€” sync Ä‘á»™c láº­p, xá»­ lÃ½ UNIQUE email constraint
            | Chiáº¿n lÆ°á»£c: XÃ³a táº¥t cáº£ users cÅ© rá»“i insert láº¡i tá»« Cloud
            | Ä‘á»ƒ trÃ¡nh conflict ID mapping vÃ  email UNIQUE
            */
            try {
                if (!empty($data['users'])) {
                    DB::beginTransaction();

                    foreach ($data['users'] as $us) {
                        // Táº¡o email unique báº±ng cÃ¡ch thÃªm cloud_id prefix
                        $email = !empty($us['email']) 
                            ? $us['id'] . '_' . $us['email'] 
                            : $us['id'] . '_noemail@deltapos.vn';

                        // XÃ³a row conflict: náº¿u email nÃ y Ä‘Ã£ tá»“n táº¡i á»Ÿ user khÃ¡c (ID khÃ¡c)
                        // thÃ¬ xÃ³a row cÅ© trÆ°á»›c Ä‘á»ƒ trÃ¡nh UNIQUE constraint violation
                        User::where('email', $email)
                            ->where('id', '!=', $us['id'])
                            ->delete();

                        // updateOrCreate vá»›i cloud ID â€” an toÃ n hÆ¡n delete all + insert
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
                $this->rollbackIfNeeded();
                Log::error('Sync users failed: ' . $e->getMessage());
                $syncErrors['users'] = $e->getMessage();
            }

            // Táº¡o báº£n Ä‘á»“ Ã¡nh xáº¡ tá»« product_id trÃªn Cloud sang product_id thá»±c táº¿ á»Ÿ Edge SQLite
            // Tá»‘i Æ°u hÃ³a hiá»‡u nÄƒng: dÃ¹ng 1 cÃ¢u query pluck duy nháº¥t thay vÃ¬ N cÃ¢u query tuáº§n tá»± (N+1 query)
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
                if ($skipTransactional) {
                    Log::info('Master sync: skipping inventories (transactional)');
                } elseif (isset($data['inventories'])) {
                    DB::beginTransaction();
                    foreach ($data['inventories'] as $inv) {
                        $localProductId = $productMap[$inv['product_id']] ?? $inv['product_id'];

                        // Kiá»ƒm tra sáº£n pháº©m cÃ³ thá»±c táº¿ tá»“n táº¡i trong SQLite cá»¥c bá»™ khÃ´ng Ä‘á»ƒ trÃ¡nh vi pháº¡m khÃ³a ngoáº¡i
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
                $this->rollbackIfNeeded();
                Log::error('Sync inventories failed: ' . $e->getMessage());
                $syncErrors['inventories'] = $e->getMessage();
            }

            // Sync INVENTORY_HISTORIES
            try {
                if ($skipTransactional) {
                    Log::info('Master sync: skipping inventory_histories (transactional)');
                } elseif (isset($data['inventory_histories'])) {
                    DB::beginTransaction();
                    
                    // Clean up synced local records to prevent duplicates before applying cloud updates
                    $syncedInputCodes = array_unique(array_column($data['inventory_histories'], 'input_code'));
                    if (!empty($syncedInputCodes)) {
                        InventoryHistory::whereIn('input_code', $syncedInputCodes)->delete();
                    }

                    foreach ($data['inventory_histories'] as $ih) {
                        $localProductId = $productMap[$ih['product_id']] ?? $ih['product_id'];

                        // Kiá»ƒm tra sáº£n pháº©m cÃ³ thá»±c táº¿ tá»“n táº¡i trong SQLite cá»¥c bá»™ khÃ´ng Ä‘á»ƒ trÃ¡nh vi pháº¡m khÃ³a ngoáº¡i
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
                $this->rollbackIfNeeded();
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
                $this->rollbackIfNeeded();
                Log::error('Sync agencies failed: ' . $e->getMessage());
                $syncErrors['agencies'] = $e->getMessage();
            }

            // Sync TYPES
            try {
                if (isset($data['types'])) {
                    DB::beginTransaction();
                    foreach ($data['types'] as $type) {
                        // Delete outdated type with same name but different ID to prevent duplicates
                        Types::where('store_id', $type['store_id'])
                            ->where('product_type_name', $type['product_type_name'])
                            ->where('id', '!=', $type['id'])
                            ->delete();
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
                $this->rollbackIfNeeded();
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
                $this->rollbackIfNeeded();
                Log::error('Sync product_types failed: ' . $e->getMessage());
                $syncErrors['product_types'] = $e->getMessage();
            }

            // Sync PRODUCT_EXTRAS
            try {
                if (isset($data['product_extras'])) {
                    DB::beginTransaction();
                    foreach ($data['product_extras'] as $pe) {
                        $localMainProductId = $productMap[$pe['main_product_id']] ?? $pe['main_product_id'];
                        $localExtraProductId = $productMap[$pe['extra_product_id']] ?? $pe['extra_product_id'];
                        if (!Product::where('id', $localMainProductId)->exists() || !Product::where('id', $localExtraProductId)->exists()) {
                            Log::warning("Sync product_extras: main_product_id {$pe['main_product_id']} or extra_product_id {$pe['extra_product_id']} product not found locally. Skipped.");
                            continue;
                        }
                        ProductExtra::updateOrCreate(
                            ['id' => $pe['id']],
                            [
                                'store_id'          => $pe['store_id'],
                                'main_product_id'   => $localMainProductId,
                                'extra_product_id'  => $localExtraProductId,
                                'admin_id'          => $pe['admin_id'] ?? null,
                                'created_at'        => $pe['created_at'] ?? now(),
                                'updated_at'        => $pe['updated_at'] ?? now(),
                            ]
                        );
                    }
                    DB::commit();
                    $syncResults['product_extras'] = count($data['product_extras']);
                }
            } catch (\Exception $e) {
                $this->rollbackIfNeeded();
                Log::error('Sync product_extras failed: ' . $e->getMessage());
                $syncErrors['product_extras'] = $e->getMessage();
            }

            // Sync CUSTOMERS
            try {
                if ($skipTransactional) {
                    Log::info('Master sync: skipping customers (transactional)');
                } elseif (isset($data['customers'])) {
                    DB::beginTransaction();
                    Customer::withoutEvents(function () use ($data) {
                        foreach ($data['customers'] as $customer) {
                            $customerPayload = [
                                'store_id'   => $customer['store_id'] ?? $this->storeId,
                                'name'       => $customer['name'] ?? '',
                                'phone'      => $customer['phone'] ?? null,
                                'email'      => $customer['email'] ?? null,
                                'address'    => $customer['address'] ?? null,
                                'birthday'   => $customer['birthday'] ?? null,
                                'note'       => $customer['note'] ?? null,
                                'admin_id'   => $customer['admin_id'] ?? null,
                                'created_at' => $customer['created_at'] ?? now(),
                                'updated_at' => $customer['updated_at'] ?? now(),
                            ];

                            $cloudId = (int)$customer['id'];
                            $phone = $customerPayload['phone'] ?? null;

                            // Try phone matching if cloud ID doesn't exist locally
                            $existingById = Customer::find($cloudId);
                            $existingByPhone = null;

                            if (!$existingById && $phone) {
                                $existingByPhone = Customer::where('store_id', $this->storeId)
                                    ->where('phone', $phone)->first();
                            }

                            if ($existingByPhone) {
                                $existingByPhone->update($customerPayload);
                                Log::info("Customer phone-matched: cloud_id={$cloudId} → local_id=" . $existingByPhone->id);
                            } else {
                                Customer::updateOrCreate(['id' => $cloudId], $customerPayload);
                            }
                        }
                    });
                    DB::commit();
                    $syncResults['customers'] = count($data['customers']);
                }
            } catch (\Exception $e) {
                $this->rollbackIfNeeded();
                Log::error('Sync customers failed: ' . $e->getMessage());
                $syncErrors['customers'] = $e->getMessage();
            }

            // Sync CASH DRAWERS
            try {
                if ($skipTransactional) {
                    Log::info('Master sync: skipping cash_drawers (transactional)');
                } elseif (isset($data['cash_drawers'])) {
                    DB::beginTransaction();
                    foreach ($data['cash_drawers'] as $cd) {
                        CashDrawer::updateOrCreate(
                            ['id' => $cd['id']],
                            [
                                'store_id'             => $cd['store_id'],
                                'start_user_id'        => $cd['start_user_id'],
                                'end_user_id'          => $cd['end_user_id'] ?? null,
                                'start_amount'         => $cd['start_amount'] ?? 0,
                                'end_amount'           => $cd['end_amount'] ?? null,
                                'owner_withdraw_amount'=> $cd['owner_withdraw_amount'] ?? null,
                                'currency_code'        => $cd['currency_code'] ?? 'VND',
                                'status'               => $cd['status'] ?? 'open',
                                'note'                 => $cd['note'] ?? '',
                                'started_at'           => $cd['started_at'],
                                'ended_at'             => $cd['ended_at'] ?? null,
                                'created_at'           => $cd['created_at'] ?? now(),
                                'updated_at'           => $cd['updated_at'] ?? now(),
                            ]
                        );
                    }
                    DB::commit();
                    $syncResults['cash_drawers'] = count($data['cash_drawers']);
                }
            } catch (\Exception $e) {
                $this->rollbackIfNeeded();
                Log::error('Sync cash_drawers failed: ' . $e->getMessage());
                $syncErrors['cash_drawers'] = $e->getMessage();
            }

            // Sync BANK PAYMENTS
            try {
                if (isset($data['bank_payments'])) {
                    DB::beginTransaction();
                    foreach ($data['bank_payments'] as $bp) {
                        BankPayment::updateOrCreate(
                            ['id' => $bp['id']],
                            [
                                'store_id'       => $bp['store_id'],
                                'bank_code'      => $bp['bank_code'],
                                'account_number' => $bp['account_number'],
                                'account_owner'  => $bp['account_owner'],
                                'admin_id'       => $bp['admin_id'],
                                'created_at'     => $bp['created_at'] ?? now(),
                                'updated_at'     => $bp['updated_at'] ?? now(),
                            ]
                        );
                    }
                    DB::commit();
                    $syncResults['bank_payments'] = count($data['bank_payments']);
                }
            } catch (\Exception $e) {
                $this->rollbackIfNeeded();
                Log::error('Sync bank_payments failed: ' . $e->getMessage());
                $syncErrors['bank_payments'] = $e->getMessage();
            }

            // Sync BOOKINGS
            try {
                if ($skipTransactional) {
                    Log::info('Master sync: skipping bookings (transactional)');
                } elseif (isset($data['bookings'])) {
                    DB::beginTransaction();
                    foreach ($data['bookings'] as $bk) {
                        $bookingPayload = [
                            'store_id'       => $bk['store_id'],
                            'booking_code'   => $bk['booking_code'] ?? ('BK-' . $bk['id']),
                            'user_id'        => $bk['user_id'] ?? 0,
                            'admin_id'       => $bk['admin_id'] ?? 0,
                            'time_arrival'   => $bk['time_arrival'],
                            'status'         => $bk['status'] ?? 0,
                            'customer_id'    => $bk['customer_id'] ?? 0,
                            'table_id'       => $bk['table_id'] ?? null,
                            'note'           => $bk['note'] ?? '',
                            'total_customer' => $bk['total_customer'] ?? 0,
                            'use_time'       => $bk['use_time'] ?? 0,
                            'item_list'      => $bk['item_list'] ?? null,
                            'created_at'     => $bk['created_at'] ?? now(),
                            'updated_at'     => $bk['updated_at'] ?? now(),
                        ];

                        $cloudId = (int)$bk['id'];
                        $code = $bk['booking_code'] ?? null;

                        // Try booking_code matching if cloud ID doesn't exist locally
                        $existingById = Booking::find($cloudId);
                        $existingByCode = null;

                        if (!$existingById && $code) {
                            $existingByCode = Booking::where('store_id', $this->storeId)
                                ->where('booking_code', $code)->first();
                        }

                        if ($existingByCode) {
                            $existingByCode->update($bookingPayload);
                            Log::info("Booking code-matched: cloud_id={$cloudId} → local_id=" . $existingByCode->id);
                        } else {
                            Booking::updateOrCreate(['id' => $cloudId], $bookingPayload);
                        }
                    }
                    DB::commit();
                    $syncResults['bookings'] = count($data['bookings']);
                }
            } catch (\Exception $e) {
                $this->rollbackIfNeeded();
                Log::error('Sync bookings failed: ' . $e->getMessage());
                $syncErrors['bookings'] = $e->getMessage();
            }

            // Sync PAYMENTS
            if ($skipTransactional) {
                Log::info('Master sync: skipping payments (transactional)');
            } else {
                // Build product ID map (cloud ID â†’ local ID) for payment_details FK
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
                        Payment::withoutEvents(function () use ($data, $paymentLocalProducts, &$syncedCount) {
                            PaymentDetail::withoutEvents(function () use ($data, $paymentLocalProducts, &$syncedCount) {
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
                            });
                        });
                        DB::commit();
                        $syncResults['payments'] = $syncedCount;
                    }
                } catch (\Exception $e) {
                    $this->rollbackIfNeeded();
                    Log::error('Sync payments failed: ' . $e->getMessage());
                    $syncErrors['payments'] = $e->getMessage();
                }
            }

            // Log tá»•ng káº¿t sau má»—i sync
            $hasErrors = !empty($syncErrors);

            Log::info('Master sync completed', [
                'synced' => $syncResults,
                'errors' => $syncErrors,
            ]);

            $this->markSyncMetadata(
                $hasErrors ? 'error' : 'idle',
                $hasErrors ? json_encode($syncErrors) : null
            );

            return [
                'success' => !$hasErrors,
                'partial_success' => $hasErrors && !empty($syncResults),
                'synced' => true,
                'message' => $hasErrors 
                    ? 'Master sync completed with some errors.' 
                    : 'Master data synced successfully.',
                'data' => $syncResults,
                'errors' => $hasErrors ? $syncErrors : null,
            ];

        } catch (\Exception $e) {

            Log::error('Master sync critical error: ' . $e->getMessage());
            $this->markSyncMetadata('error', $e->getMessage());

            return [
                'success' => false,
                'message' => 'Master sync failed.',
                'error'   => $e->getMessage(),
            ];
        }
    }

    private function hasMissingCoreTables(): bool
    {
        return Table::where('store_id', $this->storeId)->count() == 0
            || Category::where('store_id', $this->storeId)->count() == 0
            || Product::where('store_id', $this->storeId)->count() == 0
            || Store::where('id', $this->storeId)->count() == 0
            || User::where('store_id', $this->storeId)->count() == 0
            || Inventory::where('store_id', $this->storeId)->count() == 0;
    }

    private function buildPerTableSyncTimes(): array
    {
        return [
            // Table rows contain POS runtime fields on Edge, so local updated_at cannot be used as Cloud master watermark.
            'tables' => null,
            'categories' => $this->formatSyncTime(Category::where('store_id', $this->storeId)->max('updated_at')),
            'products' => $this->formatSyncTime(Product::where('store_id', $this->storeId)->max('updated_at')),
            'printers' => $this->formatSyncTime(Printer::where('store_id', $this->storeId)->max('updated_at')),
            // Small reference table: fetch fully to avoid store-specific methods being hidden by old/global watermarks.
            'payment_methods' => null,
            'product_time_prices' => $this->formatSyncTime(ProductTimePrice::where('store_id', $this->storeId)->max('updated_at')),
            'store' => $this->formatSyncTime(Store::where('id', $this->storeId)->max('updated_at')),
            'users' => $this->formatSyncTime(User::where('store_id', $this->storeId)->max('updated_at')),
            'inventories' => $this->formatSyncTime(Inventory::where('store_id', $this->storeId)->max('updated_at')),
            'inventory_histories' => $this->formatSyncTime(InventoryHistory::where('store_id', $this->storeId)->max('updated_at')),
            'agencies' => $this->formatSyncTime(Agency::where('store_id', $this->storeId)->max('updated_at')),
            'types' => $this->formatSyncTime(Types::where('store_id', $this->storeId)->max('updated_at')),
            'product_types' => $this->formatSyncTime(ProductType::where('store_id', $this->storeId)->max('updated_at')),
            'product_extras' => $this->formatSyncTime(ProductExtra::where('store_id', $this->storeId)->max('updated_at')),
            'bookings' => $this->formatSyncTime(Booking::where('store_id', $this->storeId)->max('updated_at')),
            'bank_payments' => $this->formatSyncTime(BankPayment::where('store_id', $this->storeId)->max('updated_at')),
            'cash_drawers' => $this->formatSyncTime(CashDrawer::where('store_id', $this->storeId)->max('updated_at')),
            'customers' => $this->formatSyncTime(Customer::where('store_id', $this->storeId)->max('updated_at')),
            'payments' => $this->formatSyncTime(Payment::where('store_id', $this->storeId)->max('updated_at')),
        ];
    }

    private function formatSyncTime($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function rollbackIfNeeded(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    private function markSyncMetadata(string $status, ?string $lastError = null): void
    {
        try {
            SyncMetadata::updateOrCreate(
                ['store_id' => $this->storeId],
                [
                    'last_sync_timestamp' => now(),
                    'sync_status' => $status,
                    'last_error' => $lastError,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to update master sync metadata', [
                'error' => $e->getMessage(),
            ]);
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
            'image'               => $product['image'] ?? null,
            
            'updated_at'  => $product['updated_at'] ?? now(),
        ];

        $existingByCode = Product::where('store_id', $product['store_id'])
                                ->where('code', $code)
                                ->first();
        if ($existingByCode && (int) $existingByCode->id !== (int) $product['id']) {
            // Reassign all local references to cloud ID to keep IDs in sync
            $oldId = $existingByCode->id;
            $cloudId = (int) $product['id'];
            PaymentDetail::where('product_id', $oldId)->update(['product_id' => $cloudId]);
            Inventory::where('product_id', $oldId)->update(['product_id' => $cloudId]);
            InventoryHistory::where('product_id', $oldId)->update(['product_id' => $cloudId]);
            ProductTimePrice::where('product_id', $oldId)->update(['product_id' => $cloudId]);
            ProductExtra::where('main_product_id', $oldId)->update(['main_product_id' => $cloudId]);
            ProductExtra::where('extra_product_id', $oldId)->update(['extra_product_id' => $cloudId]);
            $existingByCode->delete();
            $payload['created_at'] = $product['created_at'] ?? now();
            $dbProduct = Product::updateOrCreate(['id' => $cloudId], $payload);
            $dbProductId = $cloudId;

            Log::warning('Master sync product id reassigned to cloud id', [
                'old_edge_id' => $oldId,
                'cloud_id' => $cloudId,
                'code' => $code,
            ]);
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

        // Download and cache product image locally
        $cloudImageUrl = $product['image'] ?? '';
        // Normalize relative paths (e.g. "product/xxx.jpg") to full URL
        if (!empty($cloudImageUrl) && !filter_var($cloudImageUrl, FILTER_VALIDATE_URL) && !str_contains($cloudImageUrl, '/storage/product-images/')) {
            $cloudBaseUrl = rtrim(config('app.cloud_api_url', env('CLOUD_API_URL', '')), '/');
            if (!empty($cloudBaseUrl)) {
                $cloudImageUrl = $cloudBaseUrl . '/storage/' . ltrim($cloudImageUrl, '/');
            }
        }
        if (!empty($cloudImageUrl) && filter_var($cloudImageUrl, FILTER_VALIDATE_URL) && !str_contains($cloudImageUrl, '/storage/product-images/')) {
            // Check if already cached locally
            $productModel = \App\Models\Product::find($dbProductId);
            if ($productModel && $productModel->image === $cloudImageUrl) {
                try {
                    $imageContent = @file_get_contents($cloudImageUrl);
                    if ($imageContent !== false) {
                        $dir = storage_path('app/public/product-images');
                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }
                        $ext = pathinfo(parse_url($cloudImageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                        $filename = $code . '.' . $ext;
                        file_put_contents($dir . '/' . $filename, $imageContent);
                        $localPath = '/storage/product-images/' . $filename;
                        \App\Models\Product::where('id', $dbProductId)->update(['image' => $localPath]);
                        Log::info('Product image cached locally', ['code' => $code]);
                    }
                } catch (\Throwable $th) {
                    Log::warning('Failed to download product image', ['code' => $code, 'error' => $th->getMessage()]);
                }
            }
        }

        ProductTimePrice::where('product_id', $dbProductId)->delete();
        foreach ($product['time_prices'] ?? [] as $tp) {
            // XoÃ¡ báº£n ghi trÃ¹ng ID náº¿u cÃ³ (trÃ¡nh lá»—i UNIQUE constraint failed khi ID bá»‹ lá»‡ch sá»Ÿ há»¯u hoáº·c Ä‘á»“ng bá»™ ngáº¯t quÃ£ng)
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
    }
}
