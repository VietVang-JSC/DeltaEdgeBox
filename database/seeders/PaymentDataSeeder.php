<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Product;
use App\Models\Table;
use App\Models\Customer;

class PaymentDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $storeId = 1;
        $userId = 1; // Admin user

        $this->command->info('💳 Seeding payment data...');

        // Get available products, tables, customers
        $products = Product::where('store_id', $storeId)->get();
        $tables = Table::where('store_id', $storeId)->get();
        $customers = Customer::where('store_id', $storeId)->get();

        if ($products->isEmpty() || $tables->isEmpty() || $customers->isEmpty()) {
            $this->command->error('❌ Need products, tables, and customers first!');
            $this->command->info('Run: php artisan db:seed --class=ProductDataSeeder');
            return;
        }

        // Create sample payments
        $this->createPayments($storeId, $userId, $products, $tables, $customers);

        $this->command->info('✅ Payment data seeding completed!');
    }

    /**
     * Create sample payments
     */
    private function createPayments(
        int $storeId,
        int $userId,
        $products,
        $tables,
        $customers
    ): void {
        $paymentScenarios = [
            [
                'table_index' => 0,
                'customer_index' => 0,
                'items' => [
                    ['product_index' => 0, 'quantity' => 2], // 2x Cà phê đen đá
                    ['product_index' => 7, 'quantity' => 1], // 1x Mocha
                ],
                'payment_method' => 'cash',
                'note' => 'Khách mang đi',
            ],
            [
                'table_index' => 2,
                'customer_index' => 1,
                'items' => [
                    ['product_index' => 8, 'quantity' => 2], // 2x Trà đào cam sả
                    ['product_index' => 20, 'quantity' => 1], // 1x Croissant
                ],
                'payment_method' => 'cash',
                'note' => 'Ăn tại quán',
            ],
            [
                'table_index' => 4,
                'customer_index' => 2,
                'items' => [
                    ['product_index' => 12, 'quantity' => 1], // 1x Sinh tố bơ
                    ['product_index' => 13, 'quantity' => 1], // 1x Sinh tố dâu
                    ['product_index' => 22, 'quantity' => 2], // 2x Cheesecake
                ],
                'payment_method' => 'transfer',
                'note' => 'Sinh nhật',
            ],
            [
                'table_index' => 1,
                'customer_index' => 3,
                'items' => [
                    ['product_index' => 3, 'quantity' => 1], // 1x Cappuccino
                    ['product_index' => 4, 'quantity' => 1], // 1x Latte
                    ['product_index' => 26, 'quantity' => 1], // 1x Sandwich gà
                ],
                'payment_method' => 'cash',
                'note' => '',
            ],
            [
                'table_index' => 6,
                'customer_index' => 4,
                'items' => [
                    ['product_index' => 16, 'quantity' => 2], // 2x Nước ép cam
                    ['product_index' => 17, 'quantity' => 1], // 1x Nước ép táo
                    ['product_index' => 28, 'quantity' => 1], // 1x Salad Caesar
                ],
                'payment_method' => 'card',
                'note' => 'Family gathering',
            ],
        ];

        foreach ($paymentScenarios as $index => $scenario) {
            $table = $tables[$scenario['table_index']];
            $customer = $customers[$scenario['customer_index']];

            // Calculate totals
            $total = 0;
            $details = [];

            foreach ($scenario['items'] as $item) {
                $product = $products[$item['product_index']];
                $lineTotal = $product->price * $item['quantity'];
                $total += $lineTotal;

                $details[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                    'total' => $lineTotal,
                ];
            }

            // Calculate tax and discount
            $discount = $total > 200000 ? 10000 : 0; // 10k discount for orders > 200k
            $tax = round($total * 0.1); // 10% VAT
            $finalTotal = $total - $discount + $tax;

            // Create payment
            $payment = Payment::create([
                'store_id' => $storeId,
                'table_id' => $table->id,
                'customer_id' => $customer->id,
                'paid_date' => now()->subHours(rand(1, 48)), // Random time in last 2 days
                'total' => $total,
                'discount' => $discount,
                'tax' => $tax,
                'final_total' => $finalTotal,
                'payment_method' => $scenario['payment_method'],
                'note' => $scenario['note'],
                'status' => 1, // completed
                'user_id' => $userId,
            ]);

            // Create payment details
            foreach ($details as $detail) {
                PaymentDetail::create([
                    'payment_id' => $payment->id,
                    'product_id' => $detail['product']->id,
                    'quantity' => $detail['quantity'],
                    'price' => $detail['price'],
                    'total' => $detail['total'],
                    'note' => null,
                ]);
            }

            $this->command->info("    ✓ Payment #{$payment->id}: {$finalTotal}đ ({$table->name}, {$customer->name})");
        }
    }
}
