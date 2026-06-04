<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;


class InventoryController extends Controller
{
    /**
     * GET /api/inventory
     */
    public function index(Request $request): JsonResponse
    {
        $storeId = $request->query('store_id', Auth::user()->store_id ?? null);
 
        $inventory = Inventory::with(['product', 'store'])
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->orderBy('quantity', 'asc')
            ->paginate(50);
 
        return response()->json([
            'success' => true,
            'data'    => $inventory,
        ]);
    }
 
    /**
     * GET /api/inventory/low-stock
     */
    public function lowStock(Request $request): JsonResponse
    {
        $storeId = $request->query('store_id', Auth::user()->store_id ?? null);
 
        $items = Inventory::with(['product'])
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->whereColumn('quantity', '<=', 'low_stock_threshold')
            ->orderBy('quantity', 'asc')
            ->get();
 
        return response()->json([
            'success' => true,
            'count'   => $items->count(),
            'data'    => $items,
        ]);
    }
 
    /**
     * POST /api/inventory/adjust
     *
     * Body: { product_id, store_id, quantity_change, reason }
     */
    public function adjust(Request $request): JsonResponse
    {
        $request->validate([
            'product_id'      => 'required|integer|exists:products,id',
            'store_id'        => 'required|integer|exists:stores,id',
            'quantity_change' => 'required|integer|not_in:0',
            'reason'          => 'required|string|max:500',
        ]);
 
        $inventory = Inventory::where('store_id', $request->store_id)
            ->where('product_id', $request->product_id)
            ->lockForUpdate()
            ->firstOrFail();
 
        $newQty = $inventory->quantity + $request->quantity_change;
        if ($newQty < 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot adjust inventory: would result in negative stock ({$newQty})",
            ], 422);
        }
 
        DB::transaction(function () use ($inventory, $request, $newQty) {
            $before = $inventory->quantity;
            $inventory->quantity = $newQty;
            $inventory->save();
 
            InventoryTransaction::create([
                'store_id'        => $inventory->store_id,
                'product_id'      => $inventory->product_id,
                'user_id'         => Auth::id(),
                'transaction_type'=> 'adjustment',
                'quantity_change' => $request->quantity_change,
                'quantity_before' => $before,
                'quantity_after'  => $newQty,
                'note'            => $request->reason,
            ]);
        });
 
        return response()->json([
            'success' => true,
            'message' => 'Control inventory adjusted successfully',
            'data'    => $inventory->fresh(['product']),
        ]);
    }
 
    /**
     * POST /api/inventory/restock
     *
     * Body: { product_id, store_id, quantity, supplier_info }
     */
    public function restock(Request $request): JsonResponse
    {
        $request->validate([
            'product_id'    => 'required|integer|exists:products,id',
            'store_id'      => 'required|integer|exists:stores,id',
            'quantity'      => 'required|integer|min:1',
            'supplier_info' => 'nullable|string|max:500',
        ]);
 
        $inventory = Inventory::firstOrCreate(
            ['store_id' => $request->store_id, 'product_id' => $request->product_id],
            ['quantity' => 0, 'reserved_quantity' => 0, 'low_stock_threshold' => 10]
        );
 
        $note = " {$request->quantity}";
        if ($request->supplier_info) {
            $note .= " — {$request->supplier_info}";
        }
 
        $inventory->restock($request->quantity, $note);
 
        return response()->json([
            'success' => true,
            'message' => "Successfully restocked {$request->quantity} items",
            'data'    => $inventory->fresh(['product']),
        ]);
    }
 
    /**
     * GET /api/inventory/history
     *
     * Query: product_id (optional), store_id, date_from, date_to
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'store_id'   => 'required|integer|exists:stores,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date|after_or_equal:date_from',
        ]);
 
        $transactions = InventoryTransaction::with(['product', 'user', 'order'])
            ->forStore($request->store_id)
            ->when($request->product_id, fn($q) => $q->forProduct($request->product_id))
            ->dateRange($request->date_from, $request->date_to)
            ->orderBy('created_at', 'desc')
            ->paginate(100);
 
        return response()->json([
            'success' => true,
            'data'    => $transactions,
        ]);
    }
}
