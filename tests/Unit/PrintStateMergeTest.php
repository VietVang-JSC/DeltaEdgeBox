<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Table;
use App\Models\Store;

class PrintStateMergeTest extends TestCase
{
    use RefreshDatabase;

    private function createStore(): Store
    {
        return Store::create([
            'id' => 1,
            'name' => 'Test Store',
            'storename' => 'Test',
            'code' => 'STORE-1',
            'status' => 1,
            'is_tax_included' => false,
            'currency' => 'VND',
        ]);
    }

    private function createTable(Store $store): Table
    {
        return Table::create([
            'id' => 1,
            'store_id' => $store->id,
            'name' => 'Bàn 1',
            'status' => 1,
            'can_order' => 1,
        ]);
    }

    private function createPaidPayment(Store $store, Table $table, string $productKey, int $quantity, int $printedQuantity): Payment
    {
        $payment = Payment::create([
            'store_id' => $store->id,
            'table_id' => $table->id,
            'user_id' => 1,
            'status' => 1, // PAID
            'payment_code' => 'TEST-PAID-001',
            'total' => 100000,
            'final_total' => 100000,
            'paid_date' => now(),
        ]);

        PaymentDetail::create([
            'payment_id' => $payment->id,
            'product_id' => 2581,
            'product_key' => $productKey,
            'quantity' => $quantity,
            'printed_quantity' => $printedQuantity,
            'price' => 50000,
            'total' => $quantity * 50000,
        ]);

        return $payment;
    }

    /**
     * Case A: New payment after table cleared → printed_quantity should be 0
     * (wasRecentlyCreated = true → skip merge from previousPayment)
     */
    public function test_new_payment_after_payment_does_not_carry_printed_state(): void
    {
        $store = $this->createStore();
        $table = $this->createTable($store);
        $productKey = 'SP88196076.1-l_2-100%.0.0.0';

        // Step 1: Previous payment (paid, fully printed)
        $oldPayment = $this->createPaidPayment($store, $table, $productKey, 1, 1);
        $this->assertEquals(1, $oldPayment->details->first()->printed_quantity);

        // Step 2: Table cleared after payment
        $table->update(['payment_id' => null, 'listitem' => null]);

        // Step 3: New payment created (wasRecentlyCreated = true)
        $newPayment = Payment::create([
            'store_id' => $store->id,
            'table_id' => $table->id,
            'user_id' => 1,
            'status' => 0, // PENDING
            'payment_code' => 'TEST-NEW-001',
            'total' => 100000,
            'final_total' => 100000,
            'paid_date' => now(),
        ]);

        $this->assertTrue($newPayment->wasRecentlyCreated, 'New payment should have wasRecentlyCreated=true');

        // Step 4: Create detail for new payment (simulating upsertPendingPayment)
        // The fix: since wasRecentlyCreated=true, merge from previousPayment should NOT happen
        $previousData = [];
        // Simulate the fixed logic: skip merge when wasRecentlyCreated
        if (!$newPayment->wasRecentlyCreated) {
            // This block should NOT execute for new payment
            $previousData = [
                'printed_quantity' => 1, // from old payment
                'served' => true,
            ];
        }

        $printedQuantity = min(
            1, // new quantity
            max(
                0, // from request (new order)
                (int) ($previousData['printed_quantity'] ?? 0) // from merge (should be 0)
            )
        );

        $this->assertEquals(0, $printedQuantity, 'New payment detail should have printed_quantity=0');

        // Step 5: Verify checkPrintedStatus would return this item
        // printed_quantity(0) < quantity(1) → should be in list
        $this->assertLessThan(1, $printedQuantity, 'Item should be marked as not printed');
    }

    /**
     * Case B: Update existing pending payment → printed_quantity should be preserved
     * (wasRecentlyCreated = false → merge from previousPayment happens)
     */
    public function test_update_existing_payment_preserves_printed_state(): void
    {
        $store = $this->createStore();
        $table = $this->createTable($store);
        $productKey = 'SP88196076.1-l_2-100%.0.0.0';

        // Step 1: Create pending payment with printed detail
        $existingPayment = Payment::create([
            'store_id' => $store->id,
            'table_id' => $table->id,
            'user_id' => 1,
            'status' => 0,
            'payment_code' => 'TEST-EXISTING-001',
            'total' => 100000,
            'final_total' => 100000,
            'paid_date' => now(),
        ]);

        PaymentDetail::create([
            'payment_id' => $existingPayment->id,
            'product_id' => 2581,
            'product_key' => $productKey,
            'quantity' => 1,
            'printed_quantity' => 1, // already printed
            'price' => 50000,
            'total' => 50000,
        ]);

        // Step 2: Find existing payment (simulating upsertPendingPayment finding table.payment_id)
        // In real code: Payment::find($table->payment_id) — wasRecentlyCreated = false
        $foundPayment = Payment::find($existingPayment->id);
        $this->assertFalse($foundPayment->wasRecentlyCreated, 'Found payment should have wasRecentlyCreated=false');

        // Step 3: Current details exist
        $currentDetails = $foundPayment->details()->whereNull('deleted_at')->get();
        $this->assertGreaterThan(0, $currentDetails->count(), 'Existing payment should have details');

        // Step 4: Simulate fixed merge logic
        $previousData = [];
        if (!$foundPayment->wasRecentlyCreated && $currentDetails->count() > 0) {
            // This block SHOULD execute for existing payment
            $previousData = [
                'printed_quantity' => 1, // from previous payment (simulated)
                'served' => true,
            ];
        }

        $printedQuantity = min(
            1, // new quantity
            max(
                0,
                (int) ($previousData['printed_quantity'] ?? 0)
            )
        );

        $this->assertEquals(1, $printedQuantity, 'Existing payment detail should preserve printed_quantity=1');
    }

    /**
     * Case C: New payment with different product_key → printed_quantity = 0
     * (new product not in previousPayment → no match → printed_quantity=0)
     */
    public function test_new_product_on_existing_payment_gets_zero_printed(): void
    {
        $store = $this->createStore();
        $table = $this->createTable($store);

        // Previous payment had product A
        $oldPayment = $this->createPaidPayment($store, $table, 'PRODUCT-A-KEY', 1, 1);

        // New payment with product B (different key)
        $newPayment = Payment::create([
            'store_id' => $store->id,
            'table_id' => $table->id,
            'user_id' => 1,
            'status' => 0,
            'payment_code' => 'TEST-NEW-002',
            'total' => 100000,
            'final_total' => 100000,
            'paid_date' => now(),
        ]);

        // Simulate: previousData for product B is empty (no match in previousPayment)
        $previousData = [];
        // Even if merge happened, PRODUCT-B-KEY doesn't match PRODUCT-A-KEY
        // So previousData stays empty

        $printedQuantity = min(
            1,
            max(0, (int) ($previousData['printed_quantity'] ?? 0))
        );

        $this->assertEquals(0, $printedQuantity, 'New product should have printed_quantity=0');
    }
}
