<?php

namespace Database\Factories;

use App\Models\PurchaseItem; // Correct model
use App\Models\Product;     // Need Product to assign product_id
use App\Models\Purchase;    // Need Purchase for context (usually assigned via for() method)
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PurchaseItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get a random existing product or create one
        $product = Product::inRandomOrder()->first() ?? Product::factory()->create();

        $quantity = fake()->numberBetween(1, 50); // Random quantity
        // Use the product's purchase price as the unit cost for realism
        $unitCost = $product->purchase_price ?? fake()->randomFloat(2, 1, 100); // Fallback if product has no price

        return [
            // purchase_id is typically set using the ->for(Purchase::factory()) method when calling this factory
            // 'purchase_id' => Purchase::factory(), // Example if not using for()
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $quantity * $unitCost, // Calculate total cost
        ];
    }
}