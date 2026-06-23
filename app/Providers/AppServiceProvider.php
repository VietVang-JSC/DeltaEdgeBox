<?php

namespace App\Providers;

use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Booking;
use App\Models\CashDrawer;
use App\Observers\PaymentObserver;
use App\Observers\PaymentDetailObserver;
use App\Observers\BookingObserver;
use App\Observers\CashDrawerObserver;
use Illuminate\Support\ServiceProvider;
use App\Observers\CustomerObserver;
use App\Observers\TableObserver;
use App\Models\Customer;
use App\Models\Table;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            // Payment observers disabled — sync only via explicit cloud button or CLI command
            // Payment::observe(PaymentObserver::class);
            // PaymentDetail::observe(PaymentDetailObserver::class);
            Booking::observe(BookingObserver::class);
            CashDrawer::observe(CashDrawerObserver::class);
            Customer::observe(CustomerObserver::class);
            Table::observe(TableObserver::class);
        } catch (\Throwable $exception) {
            Log::critical('Failed to register sync observers', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
