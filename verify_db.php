<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== EDGE BOX DATABASE VERIFICATION ===\n\n";

// 1. Check all tables
$tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
echo "📊 Total Tables: " . count($tables) . "\n";
echo "Tables list:\n";
foreach ($tables as $table) {
    echo "  - {$table->name}\n";
}
echo "\n";

// 2. Check table structure for business tables
$businessTables = ['stores', 'customers', 'categories', 'products', 'tables', 'payments', 'payment_details'];
echo "🔍 Business Tables Structure:\n";
foreach ($businessTables as $tableName) {
    $columns = DB::select("PRAGMA table_info($tableName)");
    echo "\n  Table: $tableName (" . count($columns) . " columns)\n";
    foreach ($columns as $col) {
        echo "    - {$col->name} ({$col->type}" . ($col->notnull ? ", NOT NULL" : "") . ($col->pk ? ", PK" : "") . ")\n";
    }
}
echo "\n";

// 3. Check indexes
echo "📑 Indexes:\n";
foreach ($businessTables as $tableName) {
    $indexes = DB::select("PRAGMA index_list($tableName)");
    if (count($indexes) > 0) {
        echo "\n  $tableName:\n";
        foreach ($indexes as $idx) {
            echo "    - {$idx->name}\n";
        }
    }
}
echo "\n";

// 4. Test relationships
echo "🔗 Testing Relationships:\n";

try {
    // Test Store -> Customers
    $store = App\Models\Store::first();
    if ($store) {
        echo "  ✓ Store found: {$store->name} (ID: {$store->id})\n";

        $customerCount = $store->customers()->count();
        echo "  ✓ Store has $customerCount customers\n";

        // Test creating a customer
        $customer = new App\Models\Customer([
            'store_id' => $store->id,
            'name' => 'Test Customer',
            'phone' => '0900000001',
            'total_money' => '0',
            'point' => '0',
            'admin_id' => 1,
        ]);
        $customer->save();
        echo "  ✓ Created test customer (ID: {$customer->id})\n";

        // Test Category
        $category = new App\Models\Category([
            'store_id' => $store->id,
            'name' => 'Test Category',
            'description' => 'Test',
            'sort_order' => 1,
            'status' => true,
            'admin_id' => 1,
        ]);
        $category->save();
        echo "  ✓ Created test category (ID: {$category->id})\n";

        // Test Product
        $product = new App\Models\Product([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'code' => 'TEST001',
            'name' => 'Test Product',
            'price' => 50000,
            'sale_price' => 45000,
            'quantity' => 100,
            'unit' => 1,
            'status' => true,
            'is_combo' => false,
            'admin_id' => 1,
        ]);
        $product->save();
        echo "  ✓ Created test product (ID: {$product->id})\n";

        // Test Table
        $table = new App\Models\Table([
            'store_id' => $store->id,
            'name' => 'Bàn 1',
            'code' => 'T001',
            'capacity' => 4,
            'status' => 0,
            'admin_id' => 1,
        ]);
        $table->save();
        echo "  ✓ Created test table (ID: {$table->id})\n";

        // Test Payment with details
        $payment = new App\Models\Payment([
            'store_id' => $store->id,
            'table_id' => $table->id,
            'customer_id' => $customer->id,
            'paid_date' => now(),
            'total' => 100000,
            'discount' => 5000,
            'tax' => 10000,
            'final_total' => 105000,
            'payment_method' => 'cash',
            'note' => 'Test payment',
            'status' => 1,
            'user_id' => 1,
        ]);
        $payment->save();
        echo "  ✓ Created test payment (ID: {$payment->id})\n";

        $paymentDetail = new App\Models\PaymentDetail([
            'payment_id' => $payment->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 50000,
            'total' => 100000,
            'note' => 'Test detail',
        ]);
        $paymentDetail->save();
        echo "  ✓ Created test payment detail (ID: {$paymentDetail->id})\n";

        // Test relationship queries
        $paymentWithDetails = App\Models\Payment::with(['details.product', 'customer', 'table'])->find($payment->id);
        echo "  ✓ Relationship query works: Payment #{$payment->id} has " . $paymentWithDetails->details->count() . " details\n";
        echo "  ✓ Customer: {$paymentWithDetails->customer->name}\n";
        echo "  ✓ Table: {$paymentWithDetails->table->name}\n";
        echo "  ✓ Product in detail: {$paymentWithDetails->details[0]->product->name}\n";

    } else {
        echo "  ✗ No store found!\n";
    }
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n";

// 5. Check sync tables
echo "🔄 Sync Infrastructure Tables:\n";
$syncTables = ['sync_queues', 'sync_metadata', 'sync_logs', 'print_queue', 'printers'];
foreach ($syncTables as $tableName) {
    $exists = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name='$tableName'");
    echo "  " . (count($exists) > 0 ? "✓" : "✗") . " $tableName\n";
}

echo "\n=== VERIFICATION COMPLETE ===\n";
