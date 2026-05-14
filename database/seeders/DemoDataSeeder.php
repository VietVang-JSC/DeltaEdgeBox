<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Starting demo data seeding...');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Step 1: Store and Users (already done by StoreSeeder)
        $this->command->info('✓ Store and users already seeded');

        // Step 2: Products, Categories, Tables, Customers
        $this->call(ProductDataSeeder::class);

        // Step 3: Payments
        $this->call(PaymentDataSeeder::class);

        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('✅ Demo data seeding completed!');
        $this->command->info('');
        $this->command->info('Summary:');
        $this->command->info('  • 6 categories');
        $this->command->info('  • ~35 products');
        $this->command->info('  • 8 tables');
        $this->command->info('  • 8 customers');
        $this->command->info('  • 5 sample payments');
        $this->command->info('');
        $this->command->info('You can now test the POS system!');
    }
}
