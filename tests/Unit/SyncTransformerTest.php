<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\SyncTransformer;
use App\Services\SyncMapper;

class SyncTransformerTest extends TestCase
{
    /**
     * Test Category transformation: Edge Box → Backend
     */
    public function testCategoryToBackendTransformation()
    {
        $edgeBoxData = [
            'id' => 1,
            'name' => 'Beverages',
            'description' => 'Drinks and beverages',
            'image' => 'beverages.jpg',
            'sort_order' => 1,
            'status' => true,
            'parent_id' => null,
            'admin_id' => 1,
            'store_id' => 1,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ];

        $backendData = SyncTransformer::toBackend('categories', $edgeBoxData);

        $this->assertEquals('category', SyncTransformer::getBackendTableName('categories'));
        $this->assertEquals('Beverages', $backendData['category_name']);
        $this->assertEquals(1, $backendData['status']); // boolean → integer
        $this->assertArrayHasKey('category_name', $backendData);
        $this->assertArrayNotHasKey('name', $backendData);
    }

    /**
     * Test Category transformation: Backend → Edge Box
     */
    public function testCategoryToEdgeBoxTransformation()
    {
        $backendData = [
            'id' => 1,
            'category_name' => 'Beverages',
            'description' => 'Drinks and beverages',
            'image' => 'beverages.jpg',
            'sort_order' => 1,
            'status' => 1,
            'parent_id' => null,
            'admin_id' => 1,
            'store_id' => 1,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ];

        $edgeBoxData = SyncTransformer::toEdgeBox('category', $backendData);

        $this->assertEquals('categories', SyncTransformer::getEdgeBoxTableName('category'));
        $this->assertEquals('Beverages', $edgeBoxData['name']);
        $this->assertTrue($edgeBoxData['status']); // integer → boolean
        $this->assertArrayHasKey('name', $edgeBoxData);
        $this->assertArrayNotHasKey('category_name', $edgeBoxData);
    }

    /**
     * Test Product transformation: Edge Box → Backend
     */
    public function testProductToBackendTransformation()
    {
        $edgeBoxData = [
            'id' => 1,
            'code' => 'PROD001',
            'name' => 'Espresso',
            'price' => 25000,
            'sale_price' => 22000,
            'quantity' => 100,
            'unit' => 1,
            'status' => true,
            'is_combo' => false,
            'admin_id' => 1,
            'category_id' => 1,
            'store_id' => 1,
        ];

        $backendData = SyncTransformer::toBackend('products', $edgeBoxData);

        $this->assertEquals('product', SyncTransformer::getBackendTableName('products'));
        $this->assertEquals('PROD001', $backendData['product_code']);
        $this->assertEquals('Espresso', $backendData['title']);
        $this->assertEquals(25000, $backendData['price']);
        $this->assertFalse($backendData['is_combo']);
    }

    /**
     * Test Product transformation: Backend → Edge Box
     */
    public function testProductToEdgeBoxTransformation()
    {
        $backendData = [
            'id' => 1,
            'product_code' => 'PROD001',
            'title' => 'Espresso',
            'price' => 25000,
            'sale_price' => 22000,
            'quantity' => 100,
            'unit' => 1,
            'status' => 1,
            'is_combo' => 0,
            'admin_id' => 1,
            'category_id' => 1,
            'store_id' => 1,
        ];

        $edgeBoxData = SyncTransformer::toEdgeBox('product', $backendData);

        $this->assertEquals('products', SyncTransformer::getEdgeBoxTableName('product'));
        $this->assertEquals('PROD001', $edgeBoxData['code']);
        $this->assertEquals('Espresso', $edgeBoxData['name']);
        $this->assertTrue($edgeBoxData['status']);
    }

    /**
     * Test Table transformation: Edge Box → Backend
     */
    public function testTableToBackendTransformation()
    {
        $edgeBoxData = [
            'id' => 1,
            'name' => 'Table 1',
            'code' => 'T001',
            'capacity' => 4,
            'status' => 0,
            'note' => 'Window seat',
            'admin_id' => 1,
            'store_id' => 1,
        ];

        $backendData = SyncTransformer::toBackend('tables', $edgeBoxData);

        $this->assertEquals('table', SyncTransformer::getBackendTableName('tables'));
        $this->assertEquals('Table 1', $backendData['tablename']);
        $this->assertEquals('T001', $backendData['code']);
        $this->assertEquals(4, $backendData['capacity']);
    }

    /**
     * Test Payment transformation with details: Edge Box → Backend
     */
    public function testPaymentToBackendTransformation()
    {
        $paymentData = [
            'id' => 1,
            'store_id' => 1,
            'table_id' => 1,
            'customer_id' => 1,
            'paid_date' => '2024-01-15 10:30:00',
            'total' => 100000,
            'discount' => 5000,
            'tax' => 10000,
            'final_total' => 105000,
            'payment_method' => 'cash',
            'status' => 1,
            'user_id' => 1,
        ];

        $detailsData = [
            [
                'product_id' => 1,
                'quantity' => 2,
                'price' => 50000,
                'total' => 100000,
            ],
        ];

        $backendData = SyncTransformer::toBackend('payments', $paymentData, $detailsData);

        $this->assertEquals('payment', SyncTransformer::getBackendTableName('payments'));
        $this->assertEquals(100000, $backendData['valuetotal']);
        $this->assertEquals(105000, $backendData['amount_received']);
        $this->assertJsonStringEqualsJsonString(json_encode($detailsData), $backendData['items']);
    }

    /**
     * Test batch transformation
     */
    public function testBatchTransformation()
    {
        $records = [
            [
                'id' => 1,
                'name' => 'Category 1',
                'description' => null,
                'image' => null,
                'sort_order' => 0,
                'status' => true,
                'parent_id' => null,
                'admin_id' => 1,
                'store_id' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => 2,
                'name' => 'Category 2',
                'description' => null,
                'image' => null,
                'sort_order' => 0,
                'status' => true,
                'parent_id' => null,
                'admin_id' => 1,
                'store_id' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
        ];

        $transformed = SyncTransformer::batchTransform('categories', $records, 'toBackend');

        $this->assertCount(2, $transformed);
        $this->assertEquals('Category 1', $transformed[0]['category_name']);
        $this->assertEquals('Category 2', $transformed[1]['category_name']);
    }

    /**
     * Test table name mapping
     */
    public function testTableNameMapping()
    {
        $this->assertEquals('category', SyncTransformer::getBackendTableName('categories'));
        $this->assertEquals('product', SyncTransformer::getBackendTableName('products'));
        $this->assertEquals('table', SyncTransformer::getBackendTableName('tables'));
        $this->assertEquals('payment', SyncTransformer::getBackendTableName('payments'));

        $this->assertEquals('categories', SyncTransformer::getEdgeBoxTableName('category'));
        $this->assertEquals('products', SyncTransformer::getEdgeBoxTableName('product'));
        $this->assertEquals('tables', SyncTransformer::getEdgeBoxTableName('table'));
        $this->assertEquals('payments', SyncTransformer::getEdgeBoxTableName('payment'));
    }

    /**
     * Test validation with SyncMapper
     */
    public function testDataValidation()
    {
        // Valid data
        $validData = [
            'name' => 'Test Category',
            'admin_id' => 1,
        ];
        $result = SyncMapper::validateEdgeBoxData('categories', $validData);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        // Invalid data - missing required field
        $invalidData = [
            'admin_id' => 1,
            // missing 'name'
        ];
        $result = SyncMapper::validateEdgeBoxData('categories', $invalidData);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test field mapping retrieval
     */
    public function testFieldMappingRetrieval()
    {
        $mapping = SyncMapper::getFieldMapping('products');

        $this->assertArrayHasKey('code', $mapping);
        $this->assertEquals('product_code', $mapping['code']);
        $this->assertArrayHasKey('name', $mapping);
        $this->assertEquals('title', $mapping['name']);
    }

    /**
     * Test supported tables check
     */
    public function testSupportedTablesCheck()
    {
        $this->assertTrue(SyncMapper::supportsSync('categories'));
        $this->assertTrue(SyncMapper::supportsSync('products'));
        $this->assertFalse(SyncMapper::supportsSync('non_existent_table'));
    }

    /**
     * Test data cleaning for sync
     */
    public function testDataCleaning()
    {
        $data = [
            'name' => 'Test',
            'description' => null,
            'price' => 100,
            'note' => null,
        ];

        $cleaned = SyncMapper::cleanDataForSync($data);

        $this->assertArrayHasKey('name', $cleaned);
        $this->assertArrayHasKey('price', $cleaned);
        $this->assertArrayNotHasKey('description', $cleaned);
        $this->assertArrayNotHasKey('note', $cleaned);
    }

    /**
     * Test datetime formatting
     */
    public function testDateTimeFormatting()
    {
        $dateString = '2024-01-15 10:30:00';
        $formatted = SyncMapper::formatDateTime($dateString);
        $this->assertEquals('2024-01-15 10:30:00', $formatted);

        $timestamp = strtotime('2024-01-15 10:30:00');
        $formatted = SyncMapper::formatDateTime($timestamp);
        $this->assertEquals('2024-01-15 10:30:00', $formatted);

        $nullValue = SyncMapper::formatDateTime(null);
        $this->assertNull($nullValue);
    }

    /**
     * Test round-trip transformation (Edge Box → Backend → Edge Box)
     */
    public function testRoundTripTransformation()
    {
        $originalData = [
            'id' => 1,
            'name' => 'Test Category',
            'description' => 'Test description',
            'status' => true,
            'admin_id' => 1,
            'store_id' => 1,
        ];

        // Transform to backend
        $backendData = SyncTransformer::toBackend('categories', $originalData);

        // Transform back to Edge Box
        $restoredData = SyncTransformer::toEdgeBox('category', $backendData);

        // Verify data integrity
        $this->assertEquals($originalData['name'], $restoredData['name']);
        $this->assertEquals($originalData['description'], $restoredData['description']);
        $this->assertEquals($originalData['status'], $restoredData['status']);
        $this->assertEquals($originalData['admin_id'], $restoredData['admin_id']);
    }
}
