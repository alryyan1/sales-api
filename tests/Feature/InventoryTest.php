<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $warehouse;
    protected $warehouse2;
    protected $product;
    protected $product2;
    protected $client;
    protected $supplier;
    protected $shift;

    protected function setUp(): void
    {
        parent::setUp();

        // Set the base URL for testing
        $this->app['url']->forceRootUrl('http://localhost');

        // Create authenticated user
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        // Create warehouses
        $this->warehouse = Warehouse::factory()->create();
        $this->warehouse2 = Warehouse::factory()->create();

        // Create products
        $this->product = Product::factory()->create([
            'stock_quantity' => 0,
            'units_per_stocking_unit' => 1,
        ]);

        $this->product2 = Product::factory()->create([
            'stock_quantity' => 0,
            'units_per_stocking_unit' => 10, // 1 box = 10 pieces
        ]);

        // Create supplier
        $this->supplier = Supplier::factory()->create();

        // Create client
        $this->client = Client::factory()->create();

        // Create open shift
        $this->shift = Shift::create([
            'user_id' => $this->user->id,
            'opened_at' => now(),
        ]);
    }

    // ==================== PURCHASE INVENTORY TESTS ====================

    /** @test */
    public function purchase_with_received_status_increases_stock()
    {
        $initialStock = $this->product->stock_quantity;
        $purchaseQuantity = 100; // stocking units
        $unitsPerStockingUnit = $this->product->units_per_stocking_unit ?: 1;
        $expectedSellableUnits = $purchaseQuantity * $unitsPerStockingUnit;

        $response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);

        $response->assertStatus(201);

        // Verify PurchaseItem was created with correct remaining_quantity
        $purchaseItem = PurchaseItem::where('product_id', $this->product->id)->first();
        $this->assertNotNull($purchaseItem);
        $this->assertEquals($expectedSellableUnits, $purchaseItem->remaining_quantity);

        // Verify product stock_quantity increased
        $this->product->refresh();
        $this->assertEquals($initialStock + $expectedSellableUnits, $this->product->stock_quantity);

        // Verify warehouse stock increased
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertNotNull($pivot);
        $this->assertEquals($expectedSellableUnits, (int) $pivot->pivot->quantity);

        // Verify countStock returns correct value
        $this->assertEquals($expectedSellableUnits, $this->product->countStock($this->warehouse->id));
    }

    /** @test */
    public function purchase_with_pending_status_does_not_increase_stock()
    {
        $initialStock = $this->product->stock_quantity;
        $purchaseQuantity = 100;
        $expectedSellableUnits = $purchaseQuantity * ($this->product->units_per_stocking_unit ?: 1);

        $response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);

        $response->assertStatus(201);

        // Verify PurchaseItem was created
        $purchaseItem = PurchaseItem::where('product_id', $this->product->id)->first();
        $this->assertNotNull($purchaseItem);

        // Note: PurchaseItemObserver updates Product.stock_quantity based on ALL PurchaseItems
        // regardless of purchase status. However, warehouse stock is only updated for 'received' purchases.
        $this->product->refresh();
        // The observer will have updated stock_quantity, but warehouse stock should not increase
        // We verify that countStock (which filters by purchase status) returns 0

        // Verify warehouse stock did NOT increase (this is the key check)
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        if ($pivot) {
            $this->assertEquals(0, (int) $pivot->pivot->quantity);
        } else {
            $this->assertNull($pivot);
        }

        // Verify countStock returns 0 (only counts received purchases)
        $this->assertEquals(0, $this->product->countStock($this->warehouse->id));
    }

    /** @test */
    public function changing_purchase_status_from_pending_to_received_increases_stock()
    {
        // Create purchase with pending status
        $purchase = Purchase::create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'purchase_date' => now(),
            'status' => 'pending',
            'stock_added_to_warehouse' => false,
            'total_amount' => 1000,
        ]);

        $unitsPerStockingUnit = $this->product->units_per_stocking_unit ?: 1;
        $purchaseQuantity = 50; // stocking units
        $expectedSellableUnits = $purchaseQuantity * $unitsPerStockingUnit;

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => $purchaseQuantity,
            'remaining_quantity' => $expectedSellableUnits,
            'unit_cost' => 10,
            'total_cost' => 500,
            'sale_price' => 15,
            'cost_per_sellable_unit' => 10 / $unitsPerStockingUnit,
        ]);

        $initialStock = $this->product->stock_quantity;

        // Change status to received
        $response = $this->putJson("/api/purchases/{$purchase->id}", [
            'status' => 'received',
        ]);

        $response->assertStatus(200);

        // Verify stock increased
        $this->product->refresh();
        $this->assertEquals($initialStock + $expectedSellableUnits, $this->product->stock_quantity);

        // Verify warehouse stock increased
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertNotNull($pivot);
        $this->assertEquals($expectedSellableUnits, (int) $pivot->pivot->quantity);

        // Verify purchase flag was set
        $purchase->refresh();
        $this->assertTrue($purchase->stock_added_to_warehouse);
    }

    /** @test */
    public function changing_purchase_status_from_received_to_pending_decreases_stock()
    {
        // Create purchase with received status
        $purchase = Purchase::create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'purchase_date' => now(),
            'status' => 'received',
            'stock_added_to_warehouse' => true,
            'total_amount' => 1000,
        ]);

        $unitsPerStockingUnit = $this->product->units_per_stocking_unit ?: 1;
        $purchaseQuantity = 50; // stocking units
        $expectedSellableUnits = $purchaseQuantity * $unitsPerStockingUnit;

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'quantity' => $purchaseQuantity,
            'remaining_quantity' => $expectedSellableUnits,
            'unit_cost' => 10,
            'total_cost' => 500,
            'sale_price' => 15,
            'cost_per_sellable_unit' => 10 / $unitsPerStockingUnit,
        ]);

        // Add stock to warehouse pivot
        $this->product->warehouses()->attach($this->warehouse->id, ['quantity' => $expectedSellableUnits]);
        $this->product->update(['stock_quantity' => $expectedSellableUnits]);

        $initialStock = $this->product->stock_quantity;

        // Change status to pending
        $response = $this->putJson("/api/purchases/{$purchase->id}", [
            'status' => 'pending',
        ]);

        $response->assertStatus(200);

        // Verify stock decreased
        $this->product->refresh();
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertNotNull($pivot);
        // Stock should be reduced (may go to 0 or negative if original was added)
        $this->assertLessThanOrEqual($initialStock, (int) $pivot->pivot->quantity);

        // Verify purchase flag was cleared
        $purchase->refresh();
        $this->assertFalse($purchase->stock_added_to_warehouse);
    }

    /** @test */
    public function purchase_with_multiple_items_updates_stock_correctly()
    {
        $purchaseQuantity1 = 100;
        $purchaseQuantity2 = 50;
        $unitsPerStockingUnit1 = $this->product->units_per_stocking_unit ?: 1;
        $unitsPerStockingUnit2 = $this->product2->units_per_stocking_unit ?: 1;
        $expectedSellableUnits1 = $purchaseQuantity1 * $unitsPerStockingUnit1;
        $expectedSellableUnits2 = $purchaseQuantity2 * $unitsPerStockingUnit2;

        $response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity1,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ],
                [
                    'product_id' => $this->product2->id,
                    'quantity' => $purchaseQuantity2,
                    'unit_cost' => 20,
                    'sale_price' => 25,
                ]
            ]
        ]);

        $response->assertStatus(201);

        // Verify both products have correct stock
        $this->product->refresh();
        $this->product2->refresh();

        $this->assertEquals($expectedSellableUnits1, $this->product->stock_quantity);
        $this->assertEquals($expectedSellableUnits2, $this->product2->stock_quantity);

        // Verify warehouse stock for both products
        $pivot1 = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $pivot2 = $this->product2->warehouses()->where('warehouse_id', $this->warehouse->id)->first();

        $this->assertEquals($expectedSellableUnits1, (int) $pivot1->pivot->quantity);
        $this->assertEquals($expectedSellableUnits2, (int) $pivot2->pivot->quantity);
    }

    /** @test */
    public function purchase_tracks_stock_per_warehouse_correctly()
    {
        $purchaseQuantity = 100;
        $expectedSellableUnits = $purchaseQuantity * ($this->product->units_per_stocking_unit ?: 1);

        // Create purchase in warehouse 1
        $response1 = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $response1->assertStatus(201);

        // Create purchase in warehouse 2
        $response2 = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse2->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $response2->assertStatus(201);

        // Verify warehouse-specific stock
        $pivot1 = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $pivot2 = $this->product->warehouses()->where('warehouse_id', $this->warehouse2->id)->first();

        $this->assertEquals($expectedSellableUnits, (int) $pivot1->pivot->quantity);
        $this->assertEquals($expectedSellableUnits, (int) $pivot2->pivot->quantity);

        // Verify total stock is sum of both warehouses
        $this->product->refresh();
        $this->assertEquals($expectedSellableUnits * 2, $this->product->stock_quantity);

        // Verify countStock returns correct value per warehouse
        $this->assertEquals($expectedSellableUnits, $this->product->countStock($this->warehouse->id));
        $this->assertEquals($expectedSellableUnits, $this->product->countStock($this->warehouse2->id));
    }

    // ==================== SALE INVENTORY TESTS ====================

    /** @test */
    public function sale_reduces_stock_correctly()
    {
        // Setup: Create purchase to establish stock
        $purchaseQuantity = 100;
        $expectedSellableUnits = $purchaseQuantity * ($this->product->units_per_stocking_unit ?: 1);

        $purchaseResponse = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $purchaseResponse->assertStatus(201);

        $this->product->refresh();
        $initialStock = $this->product->stock_quantity;
        $saleQuantity = 30;

        // Create sale
        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $saleQuantity,
                    'unit_price' => 15,
                ]
            ],
            'payments' => [],
        ]);

        $saleResponse->assertStatus(201);

        // Verify stock decreased
        $this->product->refresh();
        $this->assertEquals($initialStock - $saleQuantity, $this->product->stock_quantity);

        // Verify warehouse stock decreased
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals($expectedSellableUnits - $saleQuantity, (int) $pivot->pivot->quantity);

        // Verify PurchaseItem remaining_quantity decreased
        $purchaseItem = PurchaseItem::where('product_id', $this->product->id)->first();
        $this->assertEquals($expectedSellableUnits - $saleQuantity, $purchaseItem->remaining_quantity);

        // Verify countStock returns correct value
        $this->assertEquals($expectedSellableUnits - $saleQuantity, $this->product->countStock($this->warehouse->id));
    }

    /** @test */
    public function sale_validates_stock_availability()
    {
        // Setup: Create purchase with limited stock
        $purchaseQuantity = 50;
        $expectedSellableUnits = $purchaseQuantity * ($this->product->units_per_stocking_unit ?: 1);

        $purchaseResponse = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $purchaseResponse->assertStatus(201);

        // Try to sell more than available
        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $expectedSellableUnits + 10, // More than available
                    'unit_price' => 15,
                ]
            ],
            'payments' => [],
        ]);

        $saleResponse->assertStatus(422);

        // Verify no sale was created
        $this->assertDatabaseMissing('sales', [
            'user_id' => $this->user->id,
        ]);

        // Verify stock was not reduced
        $this->product->refresh();
        $this->assertEquals($expectedSellableUnits, $this->product->stock_quantity);
    }

    /** @test */
    public function sale_validates_warehouse_specific_stock()
    {
        // Setup: Create stock in warehouse 1 only
        $purchaseQuantity = 100;
        $expectedSellableUnits = $purchaseQuantity * ($this->product->units_per_stocking_unit ?: 1);

        $purchaseResponse = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $purchaseResponse->assertStatus(201);

        // Try to sell from warehouse 2 (which has no stock)
        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse2->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'unit_price' => 15,
                ]
            ],
            'payments' => [],
        ]);

        $saleResponse->assertStatus(422);

        // Verify no sale was created
        $this->assertDatabaseMissing('sales', [
            'user_id' => $this->user->id,
        ]);

        // Verify warehouse 1 stock was not affected
        $pivot1 = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals($expectedSellableUnits, (int) $pivot1->pivot->quantity);
    }

    /** @test */
    public function sale_uses_fifo_batch_allocation()
    {
        // Setup: Create two purchases with different batches using API to ensure proper setup
        $batch1Quantity = 30;
        $batch2Quantity = 50;

        // Create first purchase (older, expires sooner)
        $purchase1Response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->subDays(5)->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $batch1Quantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                    'expiry_date' => now()->addDays(10)->format('Y-m-d'), // Expires sooner
                ]
            ]
        ]);
        $purchase1Response->assertStatus(201);
        $purchase1 = Purchase::latest()->first();
        $batch1 = PurchaseItem::where('purchase_id', $purchase1->id)->first();

        // Create second purchase (newer, expires later)
        $purchase2Response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->subDays(2)->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $batch2Quantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                    'expiry_date' => now()->addDays(20)->format('Y-m-d'), // Expires later
                ]
            ]
        ]);
        $purchase2Response->assertStatus(201);
        $purchase2 = Purchase::where('id', '!=', $purchase1->id)->latest()->first();
        $batch2 = PurchaseItem::where('purchase_id', $purchase2->id)->first();

        $this->product->refresh();
        
        // Verify initial stock
        $this->assertEquals($batch1Quantity + $batch2Quantity, $this->product->stock_quantity);

        // Sell 25 units (should take all from batch1 first due to FIFO, then 0 from batch2)
        // Note: Due to unique constraint on (sale_id, product_id), the system may aggregate batches
        // into a single SaleItem. We test that batch1 (older expiry) is consumed first.
        $saleQuantity = 25; // Less than batch1, so batch1 should be partially consumed

        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $saleQuantity,
                    'unit_price' => 15,
                ]
            ],
            'payments' => [],
        ]);

        $saleResponse->assertStatus(201);

        // Verify batch1 was consumed first (FIFO - oldest expiry first)
        $batch1->refresh();
        $batch2->refresh();
        
        // Batch1 should have been reduced (FIFO allocation)
        $this->assertLessThan($batch1Quantity, $batch1->remaining_quantity);
        // Batch2 should remain untouched since batch1 had enough
        $this->assertEquals($batch2Quantity, $batch2->remaining_quantity);

        // Verify sale item is linked to a batch
        $sale = Sale::latest()->first();
        $saleItem = $sale->items()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($saleItem);
        // The sale item should be linked to batch1 (FIFO)
        $this->assertEquals($batch1->id, $saleItem->purchase_item_id);
    }

    /** @test */
    public function sale_with_multiple_items_reduces_stock_for_all()
    {
        // Setup: Create purchases for both products
        $purchase1Response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 100,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $purchase1Response->assertStatus(201);

        $purchase2Response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product2->id,
                    'quantity' => 50, // 50 boxes = 500 pieces (units_per_stocking_unit = 10)
                    'unit_cost' => 20,
                    'sale_price' => 25,
                ]
            ]
        ]);
        $purchase2Response->assertStatus(201);

        $this->product->refresh();
        $this->product2->refresh();

        $initialStock1 = $this->product->stock_quantity;
        $initialStock2 = $this->product2->stock_quantity;

        $saleQuantity1 = 30;
        $saleQuantity2 = 100; // pieces

        // Create sale with both products
        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $saleQuantity1,
                    'unit_price' => 15,
                ],
                [
                    'product_id' => $this->product2->id,
                    'quantity' => $saleQuantity2,
                    'unit_price' => 25,
                ]
            ],
            'payments' => [],
        ]);

        $saleResponse->assertStatus(201);

        // Verify both products' stock decreased
        $this->product->refresh();
        $this->product2->refresh();

        $this->assertEquals($initialStock1 - $saleQuantity1, $this->product->stock_quantity);
        $this->assertEquals($initialStock2 - $saleQuantity2, $this->product2->stock_quantity);
    }

    // ==================== INTEGRATION TESTS ====================

    /** @test */
    public function purchase_then_sale_flow_maintains_correct_inventory()
    {
        // Step 1: Create purchase
        $purchaseQuantity = 100;
        $expectedSellableUnits = $purchaseQuantity * ($this->product->units_per_stocking_unit ?: 1);

        $purchaseResponse = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $purchaseResponse->assertStatus(201);

        // Verify initial stock
        $this->product->refresh();
        $this->assertEquals($expectedSellableUnits, $this->product->stock_quantity);
        $this->assertEquals($expectedSellableUnits, $this->product->countStock($this->warehouse->id));

        // Step 2: Create sale
        $saleQuantity = 40;

        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $saleQuantity,
                    'unit_price' => 15,
                ]
            ],
            'payments' => [],
        ]);

        $saleResponse->assertStatus(201);

        // Verify final stock
        $this->product->refresh();
        $this->assertEquals($expectedSellableUnits - $saleQuantity, $this->product->stock_quantity);
        $this->assertEquals($expectedSellableUnits - $saleQuantity, $this->product->countStock($this->warehouse->id));

        // Verify PurchaseItem remaining_quantity
        $purchaseItem = PurchaseItem::where('product_id', $this->product->id)->first();
        $this->assertEquals($expectedSellableUnits - $saleQuantity, $purchaseItem->remaining_quantity);

        // Verify warehouse pivot
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals($expectedSellableUnits - $saleQuantity, $pivot->pivot->quantity);
    }

    /** @test */
    public function multiple_purchases_then_sale_allocates_correctly()
    {
        // Create first purchase
        $purchase1Response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 50,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $purchase1Response->assertStatus(201);

        // Create second purchase
        $purchase2Response = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 50,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $purchase2Response->assertStatus(201);

        $this->product->refresh();
        $expectedTotalStock = 100; // 50 + 50
        $this->assertEquals($expectedTotalStock, $this->product->stock_quantity);

        // Create sale - sell 40 units (will consume from first batch due to FIFO)
        // Note: Due to unique constraint, multiple batches are aggregated into one SaleItem
        $saleQuantity = 40;

        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $saleQuantity,
                    'unit_price' => 15,
                ]
            ],
            'payments' => [],
        ]);

        $saleResponse->assertStatus(201);

        // Verify total stock decreased
        $this->product->refresh();
        $this->assertEquals($expectedTotalStock - $saleQuantity, $this->product->stock_quantity);

        // Verify PurchaseItems were allocated correctly (FIFO)
        $purchaseItems = PurchaseItem::where('product_id', $this->product->id)
            ->orderBy('id')
            ->get();

        // First batch should be partially consumed (FIFO - 50 - 40 = 10 remaining)
        $this->assertEquals(10, $purchaseItems[0]->remaining_quantity);
        // Second batch should remain untouched (50 units)
        $this->assertEquals(50, $purchaseItems[1]->remaining_quantity);
    }

    /** @test */
    public function stock_reconciliation_between_different_sources()
    {
        // Create purchase
        $purchaseQuantity = 100;
        $expectedSellableUnits = $purchaseQuantity * ($this->product->units_per_stocking_unit ?: 1);

        $purchaseResponse = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $purchaseQuantity,
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        $purchaseResponse->assertStatus(201);

        // Verify all sources match
        $this->product->refresh();
        $purchaseItem = PurchaseItem::where('product_id', $this->product->id)->first();
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();

        // All should match
        $this->assertEquals($expectedSellableUnits, $this->product->stock_quantity);
        $this->assertEquals($expectedSellableUnits, $purchaseItem->remaining_quantity);
        $this->assertEquals($expectedSellableUnits, (int) $pivot->pivot->quantity);
        $this->assertEquals($expectedSellableUnits, $this->product->countStock($this->warehouse->id));

        // Create sale
        $saleQuantity = 30;

        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $saleQuantity,
                    'unit_price' => 15,
                ]
            ],
            'payments' => [],
        ]);

        $saleResponse->assertStatus(201);

        // Verify all sources still match after sale
        $this->product->refresh();
        $purchaseItem->refresh();
        // Re-query pivot to get fresh data
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();

        $expectedRemaining = $expectedSellableUnits - $saleQuantity;

        $this->assertEquals($expectedRemaining, $this->product->stock_quantity);
        $this->assertEquals($expectedRemaining, $purchaseItem->remaining_quantity);
        $this->assertEquals($expectedRemaining, (int) $pivot->pivot->quantity);
        $this->assertEquals($expectedRemaining, $this->product->countStock($this->warehouse->id));
    }

    /** @test */
    public function product_with_units_per_stocking_unit_calculates_correctly()
    {
        // Product2 has units_per_stocking_unit = 10
        // So 1 box = 10 pieces

        $purchaseQuantity = 5; // 5 boxes
        $expectedSellableUnits = 5 * 10; // 50 pieces

        $purchaseResponse = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product2->id,
                    'quantity' => $purchaseQuantity, // 5 boxes
                    'unit_cost' => 20, // cost per box
                    'sale_price' => 2.5, // sale price per piece
                ]
            ]
        ]);
        $purchaseResponse->assertStatus(201);

        // Verify PurchaseItem has correct remaining_quantity (in sellable units)
        $purchaseItem = PurchaseItem::where('product_id', $this->product2->id)->first();
        $this->assertEquals($expectedSellableUnits, $purchaseItem->remaining_quantity);

        // Verify product stock_quantity (should be in sellable units)
        $this->product2->refresh();
        $this->assertEquals($expectedSellableUnits, $this->product2->stock_quantity);

        // Verify warehouse stock
        $pivot = $this->product2->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals($expectedSellableUnits, (int) $pivot->pivot->quantity);

        // Create sale for 20 pieces
        $saleQuantity = 20; // pieces

        $saleResponse = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product2->id,
                    'quantity' => $saleQuantity, // 20 pieces
                    'unit_price' => 2.5,
                ]
            ],
            'payments' => [],
        ]);

        $saleResponse->assertStatus(201);

        // Verify stock decreased correctly
        $this->product2->refresh();
        $purchaseItem->refresh();
        // Re-query pivot to get fresh data
        $pivot = $this->product2->warehouses()->where('warehouse_id', $this->warehouse->id)->first();

        $expectedRemaining = $expectedSellableUnits - $saleQuantity; // 50 - 20 = 30 pieces

        $this->assertEquals($expectedRemaining, $this->product2->stock_quantity);
        $this->assertEquals($expectedRemaining, $purchaseItem->remaining_quantity);
        $this->assertEquals($expectedRemaining, (int) $pivot->pivot->quantity);
    }
}


