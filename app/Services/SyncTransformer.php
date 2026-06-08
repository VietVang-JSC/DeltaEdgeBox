<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * SyncTransformer - Transforms data between Edge Box schema and Backend schema
 *
 * Edge Box uses optimized schema for offline-first operations,
 * while Backend uses legacy schema. This service handles the transformation.
 */
class SyncTransformer
{
    /**
     * Table name mapping: Edge Box → Backend
     */
    const TABLE_MAP = [
        'categories' => 'category',
        'products' => 'product',
        'tables' => 'table',
        'payments' => 'payment',
        'payment_details' => 'payment_detail',
        'customers' => 'customers', // same
        'stores' => 'stores', // same
        'users' => 'users', // same
    ];

    /**
     * Transform Edge Box data to Backend format for sync
     *
     * @param string $tableName Edge Box table name
     * @param array $data Edge Box record data
     * @param array $extraData Extra data (e.g., payment details)
     * @return array Transformed data for Backend
     */
    public static function toBackend(string $tableName, array $data, array $extraData = []): array
    {
        $backendTable = self::TABLE_MAP[$tableName] ?? $tableName;

        return match ($backendTable) {
            'category' => self::transformCategoryToBackend($data),
            'product' => self::transformProductToBackend($data),
            'table' => self::transformTableToBackend($data),
            'payment' => self::transformPaymentToBackend($data, $extraData),
            'payment_detail' => self::transformPaymentDetailToBackend($data),
            'customers' => self::transformCustomerToBackend($data),
            default => $data, // No transformation needed
        };
    }

    /**
     * Transform Backend data to Edge Box format for local storage
     *
     * @param string $tableName Backend table name
     * @param array $data Backend record data
     * @return array Transformed data for Edge Box
     */
    public static function toEdgeBox(string $tableName, array $data): array
    {
        $edgeBoxTable = array_flip(self::TABLE_MAP)[$tableName] ?? $tableName;

        return match ($edgeBoxTable) {
            'categories' => self::transformCategoryToEdgeBox($data),
            'products' => self::transformProductToEdgeBox($data),
            'tables' => self::transformTableToEdgeBox($data),
            'payments' => self::transformPaymentToEdgeBox($data),
            'payment_details' => self::transformPaymentDetailToEdgeBox($data),
            'customers' => self::transformCustomerToEdgeBox($data),
            default => $data, // No transformation needed
        };
    }

    /**
     * Transform Category: Edge Box → Backend
     */
    private static function transformCategoryToBackend(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'category_name' => $data['name'],
            'description' => $data['description'] ?? null,
            'image' => $data['image'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => (int) ($data['status'] ?? 1),
            'parent_id' => $data['parent_id'] ?? null,
            'admin_id' => $data['admin_id'],
            'store_id' => $data['store_id'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Category: Backend → Edge Box
     */
    private static function transformCategoryToEdgeBox(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'name' => $data['category_name'],
            'description' => $data['description'] ?? null,
            'image' => $data['image'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => (int) ($data['status'] ?? 1),
            'parent_id' => $data['parent_id'] ?? null,
            'admin_id' => $data['admin_id'],
            'store_id' => $data['store_id'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Product: Edge Box → Backend
     */
    private static function transformProductToBackend(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'product_code' => $data['code'],
            'second_product_code' => null,
            'third_product_code' => null,
            'parent_id' => null,
            'type_id' => null,
            'is_extra' => false,
            'is_combo' => $data['is_combo'] ?? false,
            'number_of_options' => null,
            'vat' => 0,
            'check' => 0,
            'title' => $data['name'],
            'min_quantity' => null,
            'price' => $data['price'],
            'status' => (int) ($data['status'] ?? 1),
            'inventory_required' => false,
            'type_final_product' => true,
            'type_commodity' => false,
            'image' => $data['image'] ?? '',
            'admin_id' => $data['admin_id'],
            'category_id' => $data['category_id'] ?? null,
            'product_group_id' => null,
            'sale_price' => $data['sale_price'] ?? null,
            'quantity' => $data['quantity'] ?? 0,
            'unit' => $data['unit'] ?? 1,
            'store_id' => $data['store_id'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Product: Backend → Edge Box
     */
    private static function transformProductToEdgeBox(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'code' => $data['product_code'],
            'name' => $data['title'],
            'description' => null,
            'image' => $data['image'] ?? null,
            'price' => $data['price'],
            'sale_price' => $data['sale_price'] ?? null,
            'quantity' => $data['quantity'] ?? 0,
            'unit' => $data['unit'] ?? 1,
            'status' => (int) ($data['status'] ?? 1),
            'is_combo' => (bool) ($data['is_combo'] ?? false),
            'admin_id' => $data['admin_id'],
            'category_id' => $data['category_id'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Table: Edge Box → Backend
     */
    private static function transformTableToBackend(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'tablename' => $data['name'],
            'listitem' => null, // JSON of items on table
            'status' => $data['status'],
            'user_id' => null,
            'userordered' => null,
            'booking_code' => null,
            'qr_token' => null,
            'admin_id' => $data['admin_id'],
            'capacity' => $data['capacity'] ?? 2,
            'code' => $data['code'],
            'note' => $data['note'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Table: Backend → Edge Box
     */
    private static function transformTableToEdgeBox(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'name' => $data['tablename'],
            'code' => $data['code'] ?? 'T' . $data['id'],
            'capacity' => $data['capacity'] ?? 2,
            'status' => $data['status'] ?? 0,
            'note' => $data['note'] ?? null,
            'admin_id' => $data['admin_id'],
            'store_id' => $data['store_id'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Payment: Edge Box → Backend
     * Note: Backend stores items as JSON in payment table
     */
    private static function transformPaymentToBackend(array $data, array $details = []): array
    {
        // Transform payment details to JSON format for backend
        $itemsJson = !empty($details) ? json_encode($details) : '[]';

        return [
            'id' => $data['id'] ?? null,
            'payment_code' => null,
            'customer_id' => $data['customer_id'],
            'reason' => null,
            'items' => $itemsJson, // Backend stores all items as JSON
            'valuetotal' => $data['total'],
            'discount' => $data['discount'] ?? 0,
            'surcharge' => 0,
            'total_tax' => $data['tax'] ?? 0,
            'amount_received' => $data['final_total'] ?? $data['total'],
            'payment_method' => $data['payment_method'] ?? 'cash',
            'status' => $data['status'],
            'user_id' => $data['user_id'],
            'admin_id' => $data['user_id'], // Assuming user is admin
            'table_id' => $data['table_id'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'paid_date' => $data['paid_date'],
            'note' => $data['note'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Payment: Backend → Edge Box
     * Note: Need to extract items from JSON and create payment_details
     */
    private static function transformPaymentToEdgeBox(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'table_id' => $data['table_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'paid_date' => $data['paid_date'] ?? $data['created_at'],
            'total' => $data['valuetotal'],
            'discount' => $data['discount'] ?? 0,
            'tax' => $data['total_tax'] ?? 0,
            'final_total' => $data['amount_received'] ?? $data['valuetotal'],
            'payment_method' => $data['payment_method'] ?? 'cash',
            'note' => $data['note'] ?? null,
            'status' => $data['status'],
            'user_id' => $data['user_id'],
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Payment Detail: Edge Box → Backend
     */
    private static function transformPaymentDetailToBackend(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'payment_id' => $data['payment_id'],
            'product_id' => $data['product_id'],
            'product_key' => null,
            'quantity' => $data['quantity'],
            'price' => $data['price'],
            'total_price' => $data['total'],
            'note' => $data['note'] ?? null,
            'product_extra' => null,
            'optional_products' => null,
            'inventory_histories' => null,
            'input_code' => null,
            'admin_id' => 1, // Will be set by backend
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Payment Detail: Backend → Edge Box
     */
    private static function transformPaymentDetailToEdgeBox(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'payment_id' => $data['payment_id'],
            'product_id' => $data['product_id'],
            'quantity' => $data['quantity'],
            'price' => $data['price'],
            'total' => $data['total_price'],
            'note' => $data['note'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Customer: Edge Box → Backend
     */
    private static function transformCustomerToBackend(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'total_money' => $data['total_money'] ?? '0',
            'point' => $data['point'] ?? '0',
            'admin_id' => $data['admin_id'],
            'store_id' => $data['store_id'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Transform Customer: Backend → Edge Box
     */
    private static function transformCustomerToEdgeBox(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'total_money' => $data['total_money'] ?? '0',
            'point' => $data['point'] ?? '0',
            'admin_id' => $data['admin_id'],
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    /**
     * Batch transform multiple records
     *
     * @param string $tableName
     * @param array $records
     * @param string $direction 'toBackend' or 'toEdgeBox'
     * @return array
     */
    public static function batchTransform(string $tableName, array $records, string $direction = 'toBackend'): array
    {
        return array_map(function ($record) use ($tableName, $direction) {
            if ($direction === 'toBackend') {
                return self::toBackend($tableName, $record);
            } else {
                return self::toEdgeBox($tableName, $record);
            }
        }, $records);
    }

    /**
     * Get backend table name from Edge Box table name
     */
    public static function getBackendTableName(string $edgeBoxTable): string
    {
        return self::TABLE_MAP[$edgeBoxTable] ?? $edgeBoxTable;
    }

    /**
     * Get Edge Box table name from backend table name
     */
    public static function getEdgeBoxTableName(string $backendTable): string
    {
        return array_flip(self::TABLE_MAP)[$backendTable] ?? $backendTable;
    }
}
