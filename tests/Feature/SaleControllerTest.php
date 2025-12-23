<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $warehouse;
    protected $product;
    protected $client;
    protected $shift;

    protected function setUp(): void
    {
        parent::setUp();

        // Set the base URL for testing
        $this->app['url']->forceRootUrl('http://localhost');

        // Create authenticated user
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        // Create warehouse
        $this->warehouse = Warehouse::factory()->create();

        // Create product with stock
        $this->product = Product::factory()->create([
            'stock_quantity' => 100,
            'units_per_stocking_unit' => 1,
        ]);

        // Create a purchase with items to establish stock using the API to ensure proper setup
        $supplier = Supplier::factory()->create();
        
        // Use the PurchaseController's store method to create purchase properly
        $purchaseResponse = $this->postJson('/api/purchases', [
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 100, // stocking units
                    'unit_cost' => 10,
                    'sale_price' => 15,
                ]
            ]
        ]);
        
        $purchaseResponse->assertStatus(201);
        $purchase = Purchase::latest()->first();
        
        // Verify purchase was created correctly
        $this->assertEquals($this->warehouse->id, $purchase->warehouse_id);
        $this->assertEquals('received', $purchase->status);
        $this->assertTrue($purchase->stock_added_to_warehouse);
        
        // Verify PurchaseItem was created
        $purchaseItem = PurchaseItem::where('purchase_id', $purchase->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertNotNull($purchaseItem);
        $this->assertEquals(100, $purchaseItem->remaining_quantity);
        
        // Refresh product to ensure relationships are loaded
        $this->product->refresh();

        // The purchase API will attach product to warehouse and update stock_quantity
        // Just refresh product to ensure relationships are loaded
        $this->product->refresh();
        
        // Verify stock_quantity was updated by the purchase
        $this->assertGreaterThan(0, $this->product->stock_quantity);
        
        // Verify the PurchaseItem is properly linked
        $purchaseItemCheck = PurchaseItem::where('product_id', $this->product->id)
            ->whereHas('purchase', function($q) {
                $q->where('warehouse_id', $this->warehouse->id)
                  ->where('status', 'received');
            })
            ->first();
        
        // Ensure countStock will find the PurchaseItem
        $this->assertNotNull($purchaseItemCheck, 'PurchaseItem should exist after purchase creation');
        $this->assertGreaterThan(0, $purchaseItemCheck->remaining_quantity);
        
        // Verify countStock works on the product instance
        $stockCount = $this->product->countStock($this->warehouse->id);
        $this->assertGreaterThan(0, $stockCount, "Product countStock should return > 0. Got: {$stockCount}");
        
        // Verify countStock works on a fresh product instance (like controller will use)
        $freshProduct = Product::find($this->product->id);
        $freshStockCount = $freshProduct->countStock($this->warehouse->id);
        $this->assertGreaterThan(0, $freshStockCount, "Fresh product countStock should return > 0. Got: {$freshStockCount}");
        
        // Force refresh product one more time before sale creation
        $this->product->refresh();
        
        // Verify countStock works on a fresh product instance (like the controller will use)
        $freshProduct = Product::find($this->product->id);
        $freshCountStock = $freshProduct->countStock($this->warehouse->id);
        $this->assertGreaterThan(0, $freshCountStock, "Fresh product countStock should return > 0. Got: {$freshCountStock}");
        
        // Ensure product is refreshed before API call
        $this->product->refresh();

        // Create client
        $this->client = Client::factory()->create();

        // Create open shift
        $this->shift = Shift::create([
            'user_id' => $this->user->id,
            'opened_at' => now(),
        ]);
    }

    /** @test */
    public function create_empty_sale_requires_open_shift()
    {
        $response = $this->postJson('/api/sales/create-empty', [
            'sale_date' => now()->format('Y-m-d'),
            'client_id' => null,
            'notes' => null,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'sale' => [
                'id',
                'user_id',
                'sale_date',
            ]
        ]);

        $this->assertDatabaseHas('sales', [
            'user_id' => $this->user->id,
            'shift_id' => $this->shift->id,
            'client_id' => null,
        ]);

        $sale = Sale::first();
        $this->assertEquals($this->shift->id, $sale->shift_id);
        $this->assertEquals($this->user->id, $sale->user_id);
    }

    /** @test */
    public function cannot_create_empty_sale_without_open_shift()
    {
        // Close the shift
        $this->shift->update(['closed_at' => now()]);

        $response = $this->postJson('/api/sales/create-empty', [
            'sale_date' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'لا توجد وردية مفتوحة. يرجى فتح وردية أولاً.',
        ]);

        $this->assertDatabaseMissing('sales', [
            'user_id' => $this->user->id,
        ]);
    }

    /** @test */
    public function cannot_create_sale_with_items_without_open_shift()
    {
        // Close the shift
        $this->shift->update(['closed_at' => now()]);

        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'unit_price' => 10,
                ]
            ],
            'payments' => [],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'لا توجد وردية مفتوحة. يرجى فتح وردية أولاً.',
        ]);

        $this->assertDatabaseMissing('sales', [
            'user_id' => $this->user->id,
        ]);
    }

    /** @test */
    public function create_empty_sale_with_client()
    {
        $response = $this->postJson('/api/sales/create-empty', [
            'sale_date' => now()->format('Y-m-d'),
            'client_id' => $this->client->id,
            'notes' => 'Test notes',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('sales', [
            'client_id' => $this->client->id,
            'notes' => 'Test notes',
        ]);
    }

    /** @test */
    public function create_sale_with_items_validates_stock_availability()
    {
        // Product has 100 units, try to sell 150
        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 150, // More than available
                    'unit_price' => 10,
                ]
            ],
            'payments' => [],
        ]);

        $response->assertStatus(422);
        
        // Verify no sale was created
        $this->assertDatabaseMissing('sales', [
            'user_id' => $this->user->id,
        ]);

        // Verify stock was not reduced
        $this->product->refresh();
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(100, $pivot->pivot->quantity);
    }

    /** @test */
    public function create_sale_with_valid_items_reduces_stock_correctly()
    {
        $initialStock = 100;
        $saleQuantity = 10;

        // Verify stock is available before sale
        $availableStock = $this->product->countStock($this->warehouse->id);
        $this->assertGreaterThan(0, $availableStock, 'Product should have stock available');

        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $saleQuantity,
                    'unit_price' => 10,
                ]
            ],
            'payments' => [],
        ]);

        $response->assertStatus(201);

        // Verify sale was created
        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertEquals(1, $sale->items()->count());

        // Verify stock was reduced
        $this->product->refresh();
        $pivot = $this->product->warehouses()->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals($initialStock - $saleQuantity, $pivot->pivot->quantity);
    }

    /** @test */
    public function create_sale_calculates_totals_correctly()
    {
        // Create a second product for the second item
        $product2 = Product::factory()->create([
            'stock_quantity' => 50,
            'units_per_stocking_unit' => 1,
        ]);
        
        // Create purchase for product2
        $supplier = Supplier::factory()->create();
        $purchase2 = Purchase::create([
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'purchase_date' => now(),
            'status' => 'received',
            'stock_added_to_warehouse' => true,
            'total_amount' => 500,
        ]);
        
        $unitsPerStockingUnit = $product2->units_per_stocking_unit ?: 1;
        $costPerSellableUnit = 10 / $unitsPerStockingUnit;
        
        PurchaseItem::create([
            'purchase_id' => $purchase2->id,
            'product_id' => $product2->id,
            'quantity' => 50,
            'remaining_quantity' => 50,
            'unit_cost' => 10,
            'total_cost' => 500,
            'sale_price' => 15,
            'cost_per_sellable_unit' => $costPerSellableUnit,
        ]);
        
        $product2->warehouses()->attach($this->warehouse->id, ['quantity' => 50]);
        $product2->update(['stock_quantity' => 50]);
        
        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'unit_price' => 10,
                ],
                [
                    'product_id' => $product2->id,
                    'quantity' => 3,
                    'unit_price' => 15,
                ]
            ],
            'payments' => [],
        ]);

        $response->assertStatus(201);

        $sale = Sale::first();
        // Total should be (5 * 10) + (3 * 15) = 50 + 45 = 95
        $this->assertEquals(95, (float) $sale->total_amount);
    }

    /** @test */
    public function create_sale_with_discount_calculates_correctly()
    {
        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'unit_price' => 10,
                ]
            ],
            'discount_amount' => 10,
            'discount_type' => 'fixed',
            'payments' => [],
        ]);

        $response->assertStatus(201);

        $sale = Sale::first();
        $this->assertEquals(100, (float) $sale->total_amount); // Subtotal
        $this->assertEquals(10, (float) $sale->discount_amount); // Discount
    }

    /** @test */
    public function create_sale_with_percentage_discount_calculates_correctly()
    {
        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $this->warehouse->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'unit_price' => 10,
                ]
            ],
            'discount_amount' => 10, // 10%
            'discount_type' => 'percentage',
            'payments' => [],
        ]);

        $response->assertStatus(201);

        $sale = Sale::first();
        $this->assertEquals(100, (float) $sale->total_amount); // Subtotal
        $this->assertEquals(10, (float) $sale->discount_amount); // 10% of 100 = 10
    }

    /** @test */
    public function create_sale_validates_warehouse_stock()
    {
        // Create second warehouse with less stock
        $warehouse2 = Warehouse::factory()->create();
        
        // Create purchase in warehouse2 with only 5 units
        $supplier = Supplier::factory()->create();
        $purchase2 = Purchase::create([
            'warehouse_id' => $warehouse2->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'purchase_date' => now(),
            'status' => 'received',
            'stock_added_to_warehouse' => true,
            'total_amount' => 50,
        ]);

        $unitsPerStockingUnit = $this->product->units_per_stocking_unit ?: 1;
        $costPerSellableUnit = 10 / $unitsPerStockingUnit;
        
        PurchaseItem::create([
            'purchase_id' => $purchase2->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'remaining_quantity' => 5,
            'unit_cost' => 10,
            'total_cost' => 50,
            'sale_price' => 15,
            'cost_per_sellable_unit' => $costPerSellableUnit,
        ]);
        
        $purchase2->refresh();

        $this->product->warehouses()->attach($warehouse2->id, ['quantity' => 5]);

        // Try to sell 10 units from warehouse2 (only has 5)
        $response = $this->postJson('/api/sales', [
            'warehouse_id' => $warehouse2->id,
            'client_id' => $this->client->id,
            'sale_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'unit_price' => 10,
                ]
            ],
            'payments' => [],
        ]);

        $response->assertStatus(422);
        
        // Verify stock in warehouse2 was not reduced
        $pivot = $this->product->warehouses()->where('warehouse_id', $warehouse2->id)->first();
        $this->assertEquals(5, $pivot->pivot->quantity);
    }
}
