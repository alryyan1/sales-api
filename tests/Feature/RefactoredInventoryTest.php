<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SaleItem;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

class RefactoredInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        // Force SQLite in-memory for this test suite
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    /** @test */
    public function it_can_create_sales_with_batch_splitting_without_unique_constraint_violation()
    {
        // 1. Setup Data
        $warehouse = Warehouse::create(['name' => 'Test Warehouse']);

        // Ensure we have a user
        $user = User::factory()->create([
            'warehouse_id' => $warehouse->id
        ]);

        $supplier = Supplier::create([
            'name' => 'Test Supplier',
            'contact_person' => 'Test Contact',
            'phone' => '1234567890'
        ]);

        $category = Category::factory()->create();

        $product = Product::factory()->create([
            'category_id' => $category->id,
            // 'warehouse_id' => $warehouse->id, // REMOVED: No such column on products table
        ]);

        // Initialize product_warehouse pivot
        $product->warehouses()->syncWithoutDetaching([$warehouse->id => ['quantity' => 0]]);

        // 2. Create Two Batches (Purchases)

        // Batch 1: 10 units @ $50
        $purchase1 = Purchase::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'purchase_date' => now(),
            'status' => 'received'
        ]);

        $batch1 = $purchase1->items()->create([
            'product_id' => $product->id,
            'quantity' => 10,
            'remaining_quantity' => 10, // Explicitly set remaining
            'unit_cost' => 50,
            'unit_price' => 100,
            'quantity_sold' => 0
        ]);
        // Trigger observer manually if needed, but 'create' should handle it if attached. 
        // Our PurchaseItemObserver logic: "created" or "updated" -> updateProductStock
        // Note: Observer triggers on 'saved' often.
        // Let's verify warehouse quantity increased.

        // Batch 2: 10 units @ $55
        $purchase2 = Purchase::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'purchase_date' => now(),
            'status' => 'received'
        ]);

        $batch2 = $purchase2->items()->create([
            'product_id' => $product->id,
            'quantity' => 10,
            'remaining_quantity' => 10,
            'unit_cost' => 55,
            'unit_price' => 100,
            'quantity_sold' => 0
        ]);

        // Refresh product to check total stock
        $this->assertEquals(20, $product->fresh()->total_stock, 'Total stock should be 20 after purchases.');
        $this->assertEquals(20, $product->getWarehouseStock($warehouse->id), 'Warehouse stock should be 20.');

        // 3. Create Sale Splitting Batches (15 units)
        // This requires 10 from Batch 1 + 5 from Batch 2

        $payload = [
            'warehouse_id' => $warehouse->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 15,
                    'unit_price' => 100
                ]
            ],
            'payment_method' => 'cash',
            'amount_paid' => 1500,
            'payment_status' => 'paid',
            'sale_date' => now()->toDateTimeString()
        ];

        $response = $this->actingAs($user)->postJson('/api/sales', $payload);

        if ($response->status() !== 201) {
            dump('Sale creation failed:', $response->json());
        }
        $response->assertStatus(201);

        $saleId = $response->json('id');

        // 4. Verify Sale Items (Constraint Check)
        $saleItems = SaleItem::where('sale_id', $saleId)->get();
        $this->assertCount(2, $saleItems, 'Should be 2 sale items due to batch splitting.');

        $item1 = $saleItems->firstWhere('purchase_item_id', $batch1->id);
        $item2 = $saleItems->firstWhere('purchase_item_id', $batch2->id);

        $this->assertNotNull($item1, 'Batch 1 item missing');
        $this->assertEquals(10, $item1->quantity, 'Batch 1 should sell 10');

        $this->assertNotNull($item2, 'Batch 2 item missing');
        $this->assertEquals(5, $item2->quantity, 'Batch 2 should sell 5');

        // 5. Verify Stock Deduction using SSOT
        $this->assertEquals(5, $product->fresh()->total_stock, 'Total stock should be 5 after sale.');
        $this->assertEquals(5, $product->getWarehouseStock($warehouse->id), 'Warehouse stock should be 5.');
    }
}
