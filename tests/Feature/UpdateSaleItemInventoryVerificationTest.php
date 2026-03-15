<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Category;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class UpdateSaleItemInventoryVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_returns_warehouse_specific_stock_after_updating_sale_item()
    {
        // 1. Setup Data
        $warehouse1 = Warehouse::create(['name' => 'Warehouse 1']);
        $warehouse2 = Warehouse::create(['name' => 'Warehouse 2']);

        $user = User::factory()->create(['warehouse_id' => $warehouse1->id]);
        Sanctum::actingAs($user);

        $shift = Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
        ]);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        // Stock in Warehouse 1: 50 units
        $product->warehouses()->attach($warehouse1->id, ['quantity' => 50]);
        // Stock in Warehouse 2: 100 units
        $product->warehouses()->attach($warehouse2->id, ['quantity' => 100]);

        // Total stock is 150
        $this->assertEquals(150, $product->fresh()->total_stock);

        // 2. Create Sale in Warehouse 1
        $sale = Sale::create([
            'user_id' => $user->id,
            'warehouse_id' => $warehouse1->id,
            'shift_id' => $shift->id,
            'sale_date' => now(),
            'status' => 'pending',
        ]);

        $saleItem = $sale->items()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 10,
            'total_price' => 50,
        ]);

        // 3. Update Sale Item Quantity via API
        $response = $this->putJson("/api/sales/{$sale->id}/items/{$saleItem->id}", [
            'quantity' => 10,
            'unit_price' => 10,
        ]);

        $response->assertStatus(200);

        // 4. Verify specific stock in response
        $responseData = $response->json('sale');
        $updatedItem = collect($responseData['items'])->firstWhere('id', $saleItem->id);

        $this->assertNotNull($updatedItem, 'Updated item missing in response');
        
        // Stock should be 45 (50 starting - 5 difference after update)
        // Previous quantity was 5, new is 10. Difference is 5.
        // 50 - 5 = 45.
        $this->assertEquals(45, $updatedItem['current_stock_quantity'], 'Should show warehouse-specific stock (45), not total stock (145)');
    }
}
