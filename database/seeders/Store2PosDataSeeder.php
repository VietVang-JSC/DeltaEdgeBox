<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class Store2PosDataSeeder extends Seeder
{
    private int $storeId = 2;
    private int $adminId = 2;

    public function run(): void
    {
        $this->seedStore();
        $this->seedUser();
        $categories = $this->seedCategories();
        $this->seedProducts($categories);
        $this->seedTables();
        $this->seedCustomers();

        $this->command?->info('Store 2 POS seed data completed.');
    }

    private function seedStore(): void
    {
        Store::updateOrCreate(
            ['id' => $this->storeId],
            [
                'name' => 'Store 2',
                'code' => 'STORE002',
                'address' => 'Store 2 address',
                'phone' => '0902000002',
                'email' => 'store2@example.com',
                'status' => true,
            ]
        );
    }

    private function seedUser(): void
    {
        User::updateOrCreate(
            ['id' => $this->adminId],
            [
                'store_id' => $this->storeId,
                'name' => 'Store 2 Staff',
                'email' => 'store2.staff@example.com',
                'password' => Hash::make('password'),
                'role' => 2,
                'status' => true,
                'phone' => '0902000003',
            ]
        );
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $categoryRows = [
            ['name' => 'Coffee', 'description' => 'Coffee drinks', 'sort_order' => 1],
            ['name' => 'Tea', 'description' => 'Tea drinks', 'sort_order' => 2],
            ['name' => 'Juice', 'description' => 'Fresh juices', 'sort_order' => 3],
            ['name' => 'Bakery', 'description' => 'Bakery and desserts', 'sort_order' => 4],
            ['name' => 'Food', 'description' => 'Light food', 'sort_order' => 5],
        ];

        $categories = [];
        foreach ($categoryRows as $row) {
            $category = Category::updateOrCreate(
                [
                    'store_id' => $this->storeId,
                    'name' => $row['name'],
                ],
                [
                    'description' => $row['description'],
                    'image' => null,
                    'sort_order' => $row['sort_order'],
                    'status' => true,
                    'parent_id' => null,
                    'admin_id' => $this->adminId,
                ]
            );

            $categories[$row['name']] = $category;
        }

        return $categories;
    }

    /**
     * @param array<string, Category> $categories
     */
    private function seedProducts(array $categories): void
    {
        $productRows = [
            ['category' => 'Coffee', 'code' => 'S2-CF001', 'name' => 'Black Coffee', 'price' => 25000],
            ['category' => 'Coffee', 'code' => 'S2-CF002', 'name' => 'Milk Coffee', 'price' => 30000],
            ['category' => 'Coffee', 'code' => 'S2-CF003', 'name' => 'Latte', 'price' => 45000],
            ['category' => 'Coffee', 'code' => 'S2-CF004', 'name' => 'Cappuccino', 'price' => 45000],
            ['category' => 'Tea', 'code' => 'S2-TE001', 'name' => 'Peach Tea', 'price' => 45000],
            ['category' => 'Tea', 'code' => 'S2-TE002', 'name' => 'Milk Tea', 'price' => 50000],
            ['category' => 'Tea', 'code' => 'S2-TE003', 'name' => 'Oolong Tea', 'price' => 40000],
            ['category' => 'Juice', 'code' => 'S2-JU001', 'name' => 'Orange Juice', 'price' => 40000],
            ['category' => 'Juice', 'code' => 'S2-JU002', 'name' => 'Apple Juice', 'price' => 45000],
            ['category' => 'Juice', 'code' => 'S2-JU003', 'name' => 'Watermelon Juice', 'price' => 35000],
            ['category' => 'Bakery', 'code' => 'S2-BA001', 'name' => 'Croissant', 'price' => 35000],
            ['category' => 'Bakery', 'code' => 'S2-BA002', 'name' => 'Cheesecake', 'price' => 55000],
            ['category' => 'Food', 'code' => 'S2-FO001', 'name' => 'Chicken Sandwich', 'price' => 55000],
            ['category' => 'Food', 'code' => 'S2-FO002', 'name' => 'Caesar Salad', 'price' => 65000],
            ['category' => 'Food', 'code' => 'S2-FO003', 'name' => 'Tomato Pasta', 'price' => 75000],
        ];

        foreach ($productRows as $row) {
            Product::updateOrCreate(
                ['code' => $row['code']],
                [
                    'store_id' => $this->storeId,
                    'category_id' => $categories[$row['category']]->id,
                    'name' => $row['name'],
                    'description' => null,
                    'image' => null,
                    'price' => $row['price'],
                    'sale_price' => null,
                    'quantity' => 100,
                    'unit' => 1,
                    'status' => true,
                    'is_combo' => false,
                    'admin_id' => $this->adminId,
                ]
            );
        }
    }

    private function seedTables(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            Table::updateOrCreate(
                [
                    'store_id' => $this->storeId,
                    'tablename' => 'Store 2 Table ' . $i,
                ],
                [
                    'listitem' => null,
                    'image' => null,
                    'status' => 0,
                    'user_id' => null,
                    'admin_id' => $this->adminId,
                    'is_show' => 1,
                    'sort_rank' => $i,
                    'userordered' => null,
                    'booking_code' => null,
                    'lock_time' => null,
                    'qr_token' => null,
                    'payment_id' => null,
                    'number_of_people' => 0,
                    'can_order' => 1,
                    'qr_code' => null,
                    'is_order_enabled' => 0,
                    'pin' => null,
                    'qr_code_token' => null,
                ]
            );
        }
    }

    private function seedCustomers(): void
    {
        $customerRows = [
            ['name' => 'Walk-in Customer', 'phone' => '0902000100'],
            ['name' => 'Store 2 Customer A', 'phone' => '0902000101'],
            ['name' => 'Store 2 Customer B', 'phone' => '0902000102'],
        ];

        foreach ($customerRows as $row) {
            Customer::updateOrCreate(
                [
                    'store_id' => $this->storeId,
                    'phone' => $row['phone'],
                ],
                [
                    'name' => $row['name'],
                    'total_money' => '0',
                    'point' => '0',
                    'admin_id' => $this->adminId,
                ]
            );
        }
    }
}
