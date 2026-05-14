<?php

namespace App\Providers;

use App\Models\Payment;
use App\Observers\PaymentObserver;
use Illuminate\Support\ServiceProvider;

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
        // Register observers (uncomment when models exist)
        // Payment::observe(PaymentObserver::class);
        // Order::observe(OrderObserver::class);
        // Customer::observe(CustomerObserver::class);
        // Table::observe(TableObserver::class);
    }
}
