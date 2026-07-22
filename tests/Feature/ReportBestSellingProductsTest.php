<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportBestSellingProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_best_selling_products_by_product_name()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $apple = Product::factory()->create(['name' => 'Apple Juice']);
        $orange = Product::factory()->create(['name' => 'Orange Juice']);

        $sale = Sale::factory()->create();
        SaleItem::factory()->for($sale)->create(['product_id' => $apple->id, 'quantity' => 5]);
        SaleItem::factory()->for($sale)->create(['product_id' => $orange->id, 'quantity' => 10]);

        $response = $this->getJson('/api/reports/stats/best-selling?product_name=Apple');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('Apple Juice', $data[0]['name']);
    }

    public function test_filters_best_selling_products_by_date_range()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create(['name' => 'In Range Product']);
        $oldProduct = Product::factory()->create(['name' => 'Out Of Range Product']);

        $sale = Sale::factory()->create();

        $inRangeItem = SaleItem::factory()->for($sale)->create(['product_id' => $product->id, 'quantity' => 3]);
        $inRangeItem->created_at = Carbon::now()->subDays(2);
        $inRangeItem->save();

        $oldItem = SaleItem::factory()->for($sale)->create(['product_id' => $oldProduct->id, 'quantity' => 7]);
        $oldItem->created_at = Carbon::now()->subDays(20);
        $oldItem->save();

        $startDate = Carbon::now()->subDays(5)->toDateString();
        $endDate = Carbon::now()->toDateString();

        $response = $this->getJson("/api/reports/stats/best-selling?start_date={$startDate}&end_date={$endDate}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('In Range Product', $data[0]['name']);
    }
}
