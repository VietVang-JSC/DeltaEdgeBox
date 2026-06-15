<?php

namespace App\Http\Controllers;

use App\Models\Table;
use App\Models\Product;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ProductTimePrice;
use App\Models\Inventory;
use App\Models\User;
use App\Models\Payment;
use App\Models\Booking;
use App\Models\PaymentDetail;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApiEdgeController extends Controller
{
      public function filter()
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                       'products' => Product::with([
                            'inventory',
                            'timePrices',
                            'combo_products'
                        ])->get(),

                        'categories' => Category::all(),

                        'customers' => Customer::all(),

                        'tables' => Table::with('user')->get(),

                        'users' => User::all(),

                        'payments' => Payment::all(),
                        
                        'payment_details' => PaymentDetail::all(),
                        
                        'bookings' => Booking::all(),


                ]
            ]);
        } catch (Throwable $e) {

            Log::error('Edge filter API failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load master data'
            ], 500);
        }
    }
}