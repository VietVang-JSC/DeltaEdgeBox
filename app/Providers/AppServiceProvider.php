<?php

namespace App\Providers;

use App\Models\Payment;
use App\Observers\PaymentObserver;
use Illuminate\Support\ServiceProvider;
use App\Observers\CustomerObserver;
use App\Observers\TableObserver;
use App\Models\Customer;
use App\Models\Table;

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
        Payment::observe(PaymentObserver::class);
        // Order::observe(OrderObserver::class);
        Customer::observe(CustomerObserver::class);
        Table::observe(TableObserver::class);
    }
}
