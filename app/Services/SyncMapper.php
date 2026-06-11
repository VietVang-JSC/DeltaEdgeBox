<?php

namespace App\Services;

/**
 * SyncMapper - Manages schema mapping configuration and validation
 *
 * Provides utilities for validating transformations and managing
 * field mappings between Edge Box and Backend schemas.
 */
class SyncMapper
{
    /**
     * Required fields for each table in Edge Box schema
     */
    const REQUIRED_FIELDS = [
        'categories' => ['name', 'admin_id'],
        'products' => ['code', 'name', 'price', 'admin_id'],
        'tables' => ['name', 'code', 'admin_id'],
        'payments' => ['paid_date', 'total', 'user_id'],
        'payment_details' => ['payment_id', 'product_id', 'quantity', 'price', 'total'],
        'customers' => ['name', 'phone', 'admin_id'],
        'stores' => ['name', 'code'],
    ];

    /**
     * Field type mappings for validation
     */
    const FIELD_TYPES = [
        'id' => 'integer',
        'store_id' => 'integer|null',
        'admin_id' => 'integer',
        'user_id' => 'integer',
        'name' => 'string',
        'code' => 'string',
        'price' => 'float|double',
        'total' => 'float|double',
        'quantity' => 'integer',
        'status' => 'boolean|integer',
        'created_at' => 'datetime|string|null',
        'updated_at' => 'datetime|string|null',
    ];

    /**
     * Validate Edge Box data before transformation
     *
     * @param string $tableName
     * @param array $data
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateEdgeBoxData(string $tableName, array $data): array
    {
        $errors = [];

        // Check if table is supported
        if (!isset(self::REQUIRED_FIELDS[$tableName])) {
            return [
                'valid' => false,
                'errors' => ["Table '$tableName' is not configured for sync"],
            ];
        }

        // Check required fields
        foreach (self::REQUIRED_FIELDS[$tableName] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                $errors[] = "Required field '$field' is missing or null";
            }
        }

        // Type validation for critical fields
        if (isset($data['price']) && !is_numeric($data['price'])) {
            $errors[] = "Field 'price' must be numeric";
        }

        if (isset($data['quantity']) && !is_int($data['quantity'])) {
            $errors[] = "Field 'quantity' must be integer";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate Backend data before importing to Edge Box
     *
     * @param string $tableName
     * @param array $data
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateBackendData(string $tableName, array $data): array
    {
        $errors = [];

        // Backend-specific validations
        if ($tableName === 'product') {
            if (empty($data['product_code'])) {
                $errors[] = "Backend product must have 'product_code'";
            }
            if (empty($data['title'])) {
                $errors[] = "Backend product must have 'title'";
            }
        }

        if ($tableName === 'category') {
            if (empty($data['category_name'])) {
                $errors[] = "Backend category must have 'category_name'";
            }
        }

        if ($tableName === 'table') {
            if (empty($data['tablename'])) {
                $errors[] = "Backend table must have 'tablename'";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get field mapping for a specific table
     *
     * @param string $tableName
     * @return array Mapping of Edge Box field → Backend field
     */
    public static function getFieldMapping(string $tableName): array
    {
        $mappings = [
            'categories' => [
                'name' => 'category_name',
                'description' => 'description',
                'image' => 'image',
                'sort_order' => 'sort_order',
                'status' => 'status',
                'parent_id' => 'parent_id',
            ],
            'products' => [
                'code' => 'product_code',
                'name' => 'title',
                'price' => 'price',
                'sale_price' => 'sale_price',
                'quantity' => 'quantity',
                'unit' => 'unit',
                'image' => 'image',
                'status' => 'status',
                'is_combo' => 'is_combo',
            ],
            'tables' => [
                'name' => 'tablename',
                'code' => 'code',
                'capacity' => 'capacity',
                'status' => 'status',
                'note' => 'note',
            ],
            'payments' => [
                'total' => 'valuetotal',
                'discount' => 'discount',
                'surcharge' => 'surcharge',
                'surcharge_reason' => 'surcharge_reason',
                'surcharge_percent' => 'surcharge_percent',
                'service_charge' => 'service_charge',
                'service_charge_amount' => 'service_charge_amount',
                'tax' => 'total_tax',
                'final_total' => 'amount_received',
                'payment_method' => 'payment_method',
                'status' => 'status',
                'paid_date' => 'paid_date',
            ],
            'payment_details' => [
                'product_key' => 'product_key',
                'quantity' => 'quantity',
                'price' => 'price',
                'total' => 'total_price',
                'note' => 'note',
                'product_extra' => 'product_extra',
                'optional_products' => 'optional_products',
                'inventory_histories' => 'inventory_histories',
                'input_code' => 'input_code',
                'delete_note' => 'delete_note',
                'detail_discount' => 'detail_discount',
                'served' => 'served',
                'tax_amount' => 'tax_amount',
                'detail_discount_excluding_tax' => 'detail_discount_excluding_tax',
                'unit_price_excluding_tax' => 'unit_price_excluding_tax',
                'discounted_price_excluding_tax' => 'discounted_price_excluding_tax',
                'printed_quantity' => 'printed_quantity',
            ],
        ];

        return $mappings[$tableName] ?? [];
    }

    /**
     * Check if a table supports bidirectional sync
     *
     * @param string $tableName
     * @return bool
     */
    public static function supportsSync(string $tableName): bool
    {
        return isset(SyncTransformer::TABLE_MAP[$tableName]);
    }

    /**
     * Get all tables that support sync
     *
     * @return array
     */
    public static function getSupportedTables(): array
    {
        return array_keys(SyncTransformer::TABLE_MAP);
    }

    /**
     * Generate sync report for debugging
     *
     * @param string $tableName
     * @param array $edgeBoxData
     * @param array $backendData
     * @return array
     */
    public static function generateSyncReport(string $tableName, array $edgeBoxData, array $backendData): array
    {
        $mapping = self::getFieldMapping($tableName);
        $report = [
            'table' => $tableName,
            'backend_table' => SyncTransformer::getBackendTableName($tableName),
            'fields_mapped' => 0,
            'fields_unmapped' => 0,
            'differences' => [],
        ];

        foreach ($mapping as $edgeField => $backendField) {
            if (isset($edgeBoxData[$edgeField]) && isset($backendData[$backendField])) {
                $report['fields_mapped']++;

                // Check for value differences
                if ($edgeBoxData[$edgeField] !== $backendData[$backendField]) {
                    $report['differences'][] = [
                        'field' => $edgeField,
                        'edge_box_value' => $edgeBoxData[$edgeField],
                        'backend_value' => $backendData[$backendField],
                    ];
                }
            } else {
                $report['fields_unmapped']++;
            }
        }

        return $report;
    }

    /**
     * Clean data for sync (remove null values, format dates, etc.)
     *
     * @param array $data
     * @return array
     */
    public static function cleanDataForSync(array $data): array
    {
        return array_filter($data, function ($value) {
            // Remove null values but keep 0, false, empty string
            return $value !== null;
        });
    }

    /**
     * Format datetime for sync (ensure consistent format)
     *
     * @param mixed $dateValue
     * @return string|null
     */
    public static function formatDateTime($dateValue): ?string
    {
        if (empty($dateValue)) {
            return null;
        }

        // If already a string in correct format
        if (is_string($dateValue) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateValue)) {
            return $dateValue;
        }

        // Convert from timestamp or DateTime object
        try {
            if ($dateValue instanceof \DateTime) {
                return $dateValue->format('Y-m-d H:i:s');
            }

            if (is_numeric($dateValue)) {
                return date('Y-m-d H:i:s', $dateValue);
            }

            return date('Y-m-d H:i:s', strtotime($dateValue));
        } catch (\Exception $e) {
            return null;
        }
    }
}
