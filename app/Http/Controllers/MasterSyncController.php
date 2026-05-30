<?php

namespace App\Http\Controllers;
use App\Services\MasterDataSyncService;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

use App\Models\Table;
use App\Models\Category;
use App\Models\Product;
use App\Models\Printer;
use App\Models\PaymentMethod;
use App\Models\ProductTimePrice;
use App\Models\Store;
use App\Models\User;

class MasterSyncController extends Controller
{
    protected MasterDataSyncService $masterSyncService;

    public function __construct(MasterDataSyncService $masterSyncService)
    {
        $this->masterSyncService = $masterSyncService;
    }

    public function sync()
    {
        $result = $this->masterSyncService->syncMasterData();

        return response()->json($result);
    }

    public function lastMasterSync()
    {
        $models = [
            Table::class,
            Category::class,
            Product::class,
            Printer::class,
            PaymentMethod::class,
            ProductTimePrice::class,
            Store::class,
            User::class,
        ];

        $times = array_filter(
            array_map(fn($model) => $model::max('updated_at'), $models)
        );
        $lastSyncTime = !empty($times) ? Carbon::parse(max($times))->toIso8601String() : null;

        return response()->json([
            'success' => true,
            'timestamp' => $lastSyncTime,
        ]);
    }
}
