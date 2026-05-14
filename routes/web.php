<?php

use Illuminate\Support\Facades\Route;
use Barryvdh\DomPDF\Facade\Pdf;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Test PDF generation routes (for development only)
Route::get('/test/receipt-pdf', function () {
    $data = [
        'store' => [
            'name' => 'DeltaPOS Coffee Shop',
            'address' => '123 Nguyen Hue, District 1, HCMC',
            'phone' => '0123-456-789',
        ],
        'order' => [
            'id' => 123,
            'created_at' => now(),
            'staff_name' => 'Nguyen Van A',
            'table_number' => 'T05',
            'customer_name' => 'Tran Thi B',
            'items' => [
                ['name' => 'Cà phê sữa đá', 'quantity' => 2, 'price' => 30000, 'note' => 'Ít đường'],
                ['name' => 'Trà đào cam sả', 'quantity' => 1, 'price' => 45000],
                ['name' => 'Bánh mì thịt', 'quantity' => 1, 'price' => 35000, 'note' => 'Không ớt'],
            ],
            'subtotal' => 140000,
            'discount' => 10000,
            'tax_rate' => 10,
            'tax_amount' => 13000,
            'total' => 143000,
            'payment_method' => 'Tiền mặt',
            'amount_paid' => 150000,
            'change_amount' => 7000,
        ],
    ];

    $pdf = Pdf::loadView('print.receipt', $data)
        ->setPaper([0, 0, 227, 1000], 'portrait');

    return $pdf->stream('receipt-' . $data['order']['id'] . '.pdf');
});

Route::get('/test/kitchen-pdf', function () {
    $data = [
        'order' => [
            'id' => 124,
            'order_type' => 'DINE-IN',
            'table_number' => 'T08',
            'created_at' => now(),
            'staff_name' => 'Le Thi C',
            'priority' => 'urgent',
            'items' => [
                ['name' => 'Phở bò tái nạm', 'quantity' => 2, 'note' => 'Nhiều hành, ít rau'],
                ['name' => 'Bún chả', 'quantity' => 1],
                ['name' => 'Cơm tấm sườn bì', 'quantity' => 1, 'note' => 'Thêm mỡ hành'],
            ],
            'special_instructions' => 'Khách dị ứng hải sản, không dùng nước mắm!',
        ],
    ];

    $pdf = Pdf::loadView('print.kitchen', $data)
        ->setPaper([0, 0, 227, 1000], 'portrait');

    return $pdf->stream('kitchen-' . $data['order']['id'] . '.pdf');
});
