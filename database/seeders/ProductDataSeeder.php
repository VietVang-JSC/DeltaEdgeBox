<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Product;
use App\Models\Table;
use App\Models\Customer;

class ProductDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $storeId = 1;
        $adminId = 1;

        $this->command->info('📦 Seeding product data...');

        // Create Categories
        $categories = $this->createCategories($storeId, $adminId);

        // Create Products for each category
        foreach ($categories as $category) {
            $this->createProducts($category, $storeId, $adminId);
        }

        // Create Tables
        $this->createTables($storeId, $adminId);

        // Create Customers
        $this->createCustomers($storeId, $adminId);

        $this->command->info('✅ Product data seeding completed!');
    }

    /**
     * Create product categories
     */
    private function createCategories(int $storeId, int $adminId): array
    {
        $this->command->info('  Creating categories...');

        $categoryData = [
            [
                'name' => 'Cà phê',
                'description' => 'Các loại cà phê',
                'sort_order' => 1,
            ],
            [
                'name' => 'Trà',
                'description' => 'Các loại trà',
                'sort_order' => 2,
            ],
            [
                'name' => 'Sinh tố',
                'description' => 'Sinh tố trái cây',
                'sort_order' => 3,
            ],
            [
                'name' => 'Nước ép',
                'description' => 'Nước ép trái cây tươi',
                'sort_order' => 4,
            ],
            [
                'name' => 'Bánh ngọt',
                'description' => 'Bánh và dessert',
                'sort_order' => 5,
            ],
            [
                'name' => 'Món ăn',
                'description' => 'Món ăn nhẹ',
                'sort_order' => 6,
            ],
        ];

        $categories = [];
        foreach ($categoryData as $data) {
            $category = Category::create([
                'store_id' => $storeId,
                'name' => $data['name'],
                'description' => $data['description'],
                'image' => null,
                'sort_order' => $data['sort_order'],
                'status' => true,
                'parent_id' => null,
                'admin_id' => $adminId,
            ]);
            $categories[] = $category;
            $this->command->info("    ✓ Created category: {$category->name}");
        }

        return $categories;
    }

    /**
     * Create products for a category
     */
    private function createProducts(Category $category, int $storeId, int $adminId): void
    {
        $products = $this->getProductsByCategory($category->name);

        foreach ($products as $productData) {
            Product::create([
                'store_id' => $storeId,
                'category_id' => $category->id,
                'code' => $productData['code'],
                'name' => $productData['name'],
                'description' => $productData['description'] ?? null,
                'image' => null,
                'price' => $productData['price'],
                'sale_price' => $productData['sale_price'] ?? null,
                'quantity' => 100,
                'unit' => 1, // item
                'status' => true,
                'is_combo' => false,
                'admin_id' => $adminId,
            ]);
        }

        $this->command->info("    ✓ Created " . count($products) . " products for: {$category->name}");
    }

    /**
     * Get product data by category
     */
    private function getProductsByCategory(string $categoryName): array
    {
        return match ($categoryName) {
            'Cà phê' => [
                ['code' => 'CF001', 'name' => 'Cà phê đen đá', 'price' => 25000],
                ['code' => 'CF002', 'name' => 'Cà phê sữa đá', 'price' => 30000],
                ['code' => 'CF003', 'name' => 'Bạc xỉu', 'price' => 35000],
                ['code' => 'CF004', 'name' => 'Cappuccino', 'price' => 45000],
                ['code' => 'CF005', 'name' => 'Latte', 'price' => 45000],
                ['code' => 'CF006', 'name' => 'Espresso', 'price' => 35000],
                ['code' => 'CF007', 'name' => 'Americano', 'price' => 40000],
                ['code' => 'CF008', 'name' => 'Mocha', 'price' => 50000, 'sale_price' => 45000],
            ],
            'Trà' => [
                ['code' => 'TR001', 'name' => 'Trà đào cam sả', 'price' => 45000],
                ['code' => 'TR002', 'name' => 'Trà vải hoa hồng', 'price' => 50000],
                ['code' => 'TR003', 'name' => 'Trà chanh dây', 'price' => 45000],
                ['code' => 'TR004', 'name' => 'Trà xanh matcha', 'price' => 55000],
                ['code' => 'TR005', 'name' => 'Trà sữa trân châu', 'price' => 50000],
                ['code' => 'TR006', 'name' => 'Trà ô long', 'price' => 40000],
            ],
            'Sinh tố' => [
                ['code' => 'ST001', 'name' => 'Sinh tố bơ', 'price' => 45000],
                ['code' => 'ST002', 'name' => 'Sinh tố dâu', 'price' => 50000],
                ['code' => 'ST003', 'name' => 'Sinh tố xoài', 'price' => 45000],
                ['code' => 'ST004', 'name' => 'Sinh tố chuối', 'price' => 40000],
                ['code' => 'ST005', 'name' => 'Sinh tố sầu riêng', 'price' => 60000],
                ['code' => 'ST006', 'name' => 'Sinh tố thanh long', 'price' => 50000],
            ],
            'Nước ép' => [
                ['code' => 'NE001', 'name' => 'Nước ép cam', 'price' => 40000],
                ['code' => 'NE002', 'name' => 'Nước ép táo', 'price' => 45000],
                ['code' => 'NE003', 'name' => 'Nước ép dưa hấu', 'price' => 35000],
                ['code' => 'NE004', 'name' => 'Nước ép cà rốt', 'price' => 40000],
                ['code' => 'NE005', 'name' => 'Nước ép dứa', 'price' => 40000],
            ],
            'Bánh ngọt' => [
                ['code' => 'BN001', 'name' => 'Croissant', 'price' => 35000],
                ['code' => 'BN002', 'name' => 'Muffin chocolate', 'price' => 40000],
                ['code' => 'BN003', 'name' => 'Cheesecake', 'price' => 55000],
                ['code' => 'BN004', 'name' => 'Tiramisu', 'price' => 60000],
                ['code' => 'BN005', 'name' => 'Brownie', 'price' => 45000],
            ],
            'Món ăn' => [
                ['code' => 'MA001', 'name' => 'Sandwich gà', 'price' => 55000],
                ['code' => 'MA002', 'name' => 'Salad Caesar', 'price' => 65000],
                ['code' => 'MA003', 'name' => 'Pasta sốt cà', 'price' => 75000],
                ['code' => 'MA004', 'name' => 'Cơm rang dương châu', 'price' => 70000],
                ['code' => 'MA005', 'name' => 'Mì ý bò bằm', 'price' => 80000],
            ],
            default => [],
        };
    }

    /**
     * Create dining tables
     */
    private function createTables(int $storeId, int $adminId): void
    {
        $this->command->info('  Creating tables...');

        $tableData = [
            ['name' => 'Bàn 1', 'code' => 'T001', 'capacity' => 2, 'note' => 'Gần cửa sổ'],
            ['name' => 'Bàn 2', 'code' => 'T002', 'capacity' => 2, 'note' => 'Gần cửa sổ'],
            ['name' => 'Bàn 3', 'code' => 'T003', 'capacity' => 4, 'note' => 'Giữa quán'],
            ['name' => 'Bàn 4', 'code' => 'T004', 'capacity' => 4, 'note' => 'Giữa quán'],
            ['name' => 'Bàn 5', 'code' => 'T005', 'capacity' => 6, 'note' => 'Phòng VIP'],
            ['name' => 'Bàn 6', 'code' => 'T006', 'capacity' => 6, 'note' => 'Phòng VIP'],
            ['name' => 'Bàn 7', 'code' => 'T007', 'capacity' => 8, 'note' => 'Sân thượng'],
            ['name' => 'Bàn 8', 'code' => 'T008', 'capacity' => 8, 'note' => 'Sân thượng'],
        ];

        foreach ($tableData as $data) {
            Table::create([
                'store_id' => $storeId,
                'name' => $data['name'],
                'code' => $data['code'],
                'capacity' => $data['capacity'],
                'status' => 0, // empty
                'note' => $data['note'],
                'admin_id' => $adminId,
            ]);
            $this->command->info("    ✓ Created: {$data['name']} (sức chứa: {$data['capacity']})");
        }
    }

    /**
     * Create sample customers
     */
    private function createCustomers(int $storeId, int $adminId): void
    {
        $this->command->info('  Creating customers...');

        $customerData = [
            ['name' => 'Nguyễn Văn A', 'phone' => '0901234567'],
            ['name' => 'Trần Thị B', 'phone' => '0912345678'],
            ['name' => 'Lê Văn C', 'phone' => '0923456789'],
            ['name' => 'Phạm Thị D', 'phone' => '0934567890'],
            ['name' => 'Hoàng Văn E', 'phone' => '0945678901'],
            ['name' => 'Vũ Thị F', 'phone' => '0956789012'],
            ['name' => 'Đặng Văn G', 'phone' => '0967890123'],
            ['name' => 'Bùi Thị H', 'phone' => '0978901234'],
        ];

        foreach ($customerData as $data) {
            Customer::create([
                'store_id' => $storeId,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'total_money' => '0',
                'point' => '0',
                'admin_id' => $adminId,
            ]);
            $this->command->info("    ✓ Created customer: {$data['name']}");
        }
    }
}
