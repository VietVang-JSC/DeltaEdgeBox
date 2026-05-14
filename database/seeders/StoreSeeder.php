<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class StoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default store
        $store = Store::create([
            'name' => 'Cửa hàng Demo',
            'code' => 'STORE001',
            'address' => '123 Đường ABC, Quận 1, TP.HCM',
            'phone' => '0901234567',
            'email' => 'demo@store.com',
            'status' => true,
        ]);

        // Create admin user for this store
        User::create([
            'store_id' => $store->id,
            'name' => 'Admin Demo',
            'email' => 'admin@demo.com',
            'phone' => '0901234567',
            'password' => Hash::make('password'),
            'role' => 1, // admin
            'status' => true,
        ]);

        // Create staff user
        User::create([
            'store_id' => $store->id,
            'name' => 'Nhân viên Demo',
            'email' => 'staff@demo.com',
            'phone' => '0909876543',
            'password' => Hash::make('password'),
            'role' => 2, // staff
            'status' => true,
        ]);

        $this->command->info('Default store and users created successfully!');
        $this->command->info('Store Code: STORE001');
        $this->command->info('Admin: admin@demo.com / password');
        $this->command->info('Staff: staff@demo.com / password');
    }
}
