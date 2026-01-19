<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product; // Correct model import
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str; // For generating SKU

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $purchasePrice = fake()->randomFloat(2, 5, 200); // Random price between 5.00 and 200.00

        // Find an existing category or create one
        $category = Category::inRandomOrder()->first() ?? Category::factory()->create();

        return [
            'name' => fake()->words(rand(2, 5), true), // Product name with 2-5 words
            'sku' => 'SKU-' . Str::upper(Str::random(8)), // Generate a somewhat unique SKU
            'description' => fake()->optional(0.8)->paragraph(), // 80% chance of description
            // Sale price is typically higher than purchase price
            // 'stock_quantity' => fake()->numberBetween(0, 500), // REMOVED: Column dropped
            'stock_alert_level' => fake()->optional(0.9, 10)->numberBetween(5, 50), // 90% chance, between 5-50, default 10
            // 'unit' => fake()->randomElement(['piece', 'kg', 'box', 'liter']), // Example if unit field exists
            'category_id' => $category->id, // <-- Assign category_id
        ];
    }

    /**
     * Indicate that the product is out of stock. (Example state)
     * Usage: Product::factory()->outOfStock()->create();
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function outOfStock(): Factory
    {
        return $this->state(fn(array $attributes) => [
            // 'stock_quantity' => 0, // REMOVED: Column dropped
        ]);
    }

    /**
     * Indicate that the product has low stock. (Example state)
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function lowStock(): Factory
    {
        // Use a callback to access other attributes if needed for calculation
        return $this->state(function (array $attributes) {
            $alertLevel = $attributes['stock_alert_level'] ?? 10; // Use defined alert level or default
            return [
                // Set stock slightly below or at alert level
                // 'stock_quantity' => fake()->numberBetween(0, $alertLevel), // REMOVED: Column dropped
            ];
        });
    }
}
