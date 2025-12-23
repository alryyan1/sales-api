<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Supplier;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class PurchaseStatusStockTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $warehouse;
    protected $product;
    protected $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        // Set the base URL for testing
        $this->app['url']->forceRootUrl('http://localhost');

        // Create basic requirements
        $this->user = User::factory()->create();
        $this->warehouse = Warehouse::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $this->product = Product::factory()->create([
            'stock_quantity' => 0,
            'units_per_stocking_unit' => 10
        ]);

        // Authenticate with Sanctum
        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_does_not_add_stock_when_created_as_pending()
    {
        $response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5, // 5 Boxes
                    'unit_cost' => 100,
                    'sale_price' => 20, // Required field
                ]
            ]
        ]);

        $response->assertStatus(201);
        $purchase = Purchase::first();

        // Verify Flag
        $this->assertFalse((bool)$purchase->stock_added_to_warehouse);

        // Verify Warehouse Stock (Should be 0 or empty)
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertNull($pivot);
    }

    /** @test */
    public function it_adds_stock_when_status_changes_to_received()
    {
        // 1. Create Purchase manually (Pending)
        $purchase = Purchase::create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'purchase_date' => now(),
            'status' => 'pending',
            'stock_added_to_warehouse' => false,
            'total_amount' => 0
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 5, // 5 Boxes
            'remaining_quantity' => 50, // 50 Items (Sellable)
            'unit_cost' => 100,
            'total_cost' => 500,
            'sale_price' => 20
        ]);

        // 2. Update Status to Received
        $response = $this->putJson("/api/purchases/{$purchase->id}", [
            'status' => 'received'
        ]);

        $response->assertStatus(200);
        $purchase->refresh();

        // 3. Verify Flag is True
        $this->assertTrue((bool)$purchase->stock_added_to_warehouse);

        // 4. Verify Stock Added (50 units)
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertNotNull($pivot);
        $this->assertEquals(50, $pivot->pivot->quantity);
    }

    /** @test */
    public function it_removes_stock_when_status_changes_back_to_pending()
    {
        // 1. Setup: Purchase that is already received and stock added
        $purchase = Purchase::create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'purchase_date' => now(),
            'status' => 'received',
            'stock_added_to_warehouse' => true,
            'total_amount' => 500
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 5, // 5 Boxes
            'remaining_quantity' => 50, // 50 Items
            'unit_cost' => 100,
            'total_cost' => 500,
            'sale_price' => 20
        ]);

        // Manually attach pre-existing stock
        $this->product->warehouses()->attach($this->warehouse->id, ['quantity' => 50]);

        // 2. Update Status to Pending
        $response = $this->putJson("/api/purchases/{$purchase->id}", [
            'status' => 'pending'
        ]);

        $response->assertStatus(200);
        $purchase->refresh();

        // 3. Verify Flag is False
        $this->assertFalse((bool)$purchase->stock_added_to_warehouse);

        // 4. Verify Stock Removed (Should be 0)
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(0, $pivot->pivot->quantity);
    }

    /** @test */
    public function it_does_not_double_count_when_updating_received_purchase_again()
    {
        // 1. Setup: Already received purchase
        $purchase = Purchase::create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'purchase_date' => now(),
            'status' => 'received',
            'stock_added_to_warehouse' => true,
            'total_amount' => 500
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'remaining_quantity' => 50,
            'unit_cost' => 100,
            'total_cost' => 500,
            'sale_price' => 20
        ]);

        $this->product->warehouses()->attach($this->warehouse->id, ['quantity' => 50]);

        // 2. Update purchase (e.g., change notes), keeping status received
        $response = $this->putJson("/api/purchases/{$purchase->id}", [
            'status' => 'received',
            'notes' => 'Updated notes'
        ]);

        $response->assertStatus(200);

        // 3. Verify Stock is STILL 50 (Not 100)
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(50, $pivot->pivot->quantity);
    }
}
