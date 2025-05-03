<?php

namespace Database\Factories;

use App\Models\SaleItem; // Correct model
use App\Models\Product;  // Need Product
use App\Models\Sale;     // Need Sale for context (usually set via for())
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SaleItem>
 */
class SaleItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SaleItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Find a product - This factory assumes stock check happens elsewhere (like SaleFactory)
        $product = Product::inRandomOrder()->first() ?? Product::factory()->create();

        // IMPORTANT: This factory DOES NOT check/decrement stock by itself.
        // It's assumed the calling context (like SaleFactory's configure method) handles stock logic.
        $quantity = fake()->numberBetween(1, 10); // Example quantity

        // Use the product's sale price
        $unitPrice = $product->sale_price ?? fake()->randomFloat(2, 10, 500); // Fallback

        return [
            // sale_id is typically set using ->for(Sale::factory()) when calling
            // 'sale_id' => Sale::factory(),
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $quantity * $unitPrice, // Calculate total
        ];
    }
}