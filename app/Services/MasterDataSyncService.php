<?php

namespace App\Services;

use App\Models\Table;
use App\Models\Product;
use App\Models\Category;
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
        $this->cloudApiUrl = env('CLOUD_API_URL');
        $this->apiKey = config('edge_box.api_key');
        $this->storeId     = env('STORE_ID');
    }

    /**
     * Sync master data  Cloud to EdgeBox
     */
    public function syncMasterData(): array
    {
        try {

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

            $data = $response->json()['data'];
            
            if (!$data) {

                return [
                    'success' => false,
                    'message' => 'Invalid response structure'
                ];
            }

            

            DB::beginTransaction();

            /*
             | TABLES
            */

            foreach ($data['tables'] ?? [] as $table) {

               Table::updateOrCreate(
                [
                    'id' => $table['id']
                ],
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

            /*
            | CATEGORIES
            */

            foreach ($data['categories'] ?? [] as $category) {

              Category::updateOrCreate(
                [
                    'id' => $category['id']
                ],
                [
                    'store_id'  => $category['store_id'],
                    'name'      => $category['category_name'], 
                    'status'    => $category['status'] ?? 1,
                    'admin_id'  => $category['admin_id'] ?? null,
                    'updated_at'=> $category['updated_at'] ?? now(),
                ]
            );
            }

            /*
            ---Products
            */

            foreach ($data['products'] ?? [] as $product) {

               Product::updateOrCreate(
                [
                    'id' => $product['id']
                ],
                [
                    'category_id' => $product['category_id'],
                    'store_id'    => $product['store_id'],
                    'name'        => $product['title'], 
                    'code'        => $product['product_code'],
                    'sku'         => $product['product_code'], 
                    'price'       => $product['price'],
                    'status'      => $product['status'],
                    'quantity'    => $product['quantity']
                                        ?? ($product['inventory']['quantity'] ?? 0),

                    'admin_id'    => $product['admin_id'] ?? null,
                    'updated_at'  => $product['updated_at'] ?? now(),
                ]
            );
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Master data synced successfully.',
                'data' => [
                    'tables'    => count($data['tables'] ?? []),
                    'categories'=> count($data['categories'] ?? []),
                    'products'  => count($data['products'] ?? []),
                ]
            ];

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('Master sync error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Master sync failed.',
                'error'   => $e->getMessage(),
            ];
        }
    }
}