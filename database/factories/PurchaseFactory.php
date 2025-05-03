<?php

namespace Database\Factories;

use App\Models\Purchase; // Correct model
use App\Models\Supplier; // Need Supplier to assign supplier_id
use App\Models\User;    // Need User to assign user_id
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str; // For reference number

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Purchase::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Ensure you have Suppliers and Users in the database first, or create them on the fly
        // Using first() or random() might fail if the respective tables are empty.
        // Using factory()->create() ensures they exist.
        $supplier = Supplier::factory()->create(); // Creates a new supplier for each purchase
        // Or if you want to pick from existing suppliers:
        // $supplier = Supplier::inRandomOrder()->first() ?? Supplier::factory()->create();

        $user = User::inRandomOrder()->first() ?? User::factory()->create(); // Pick random existing user or create one

        return [
            // Use the ID of the created/fetched supplier and user
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'purchase_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'), // Format as date string
            'reference_number' => 'PO-' . Str::upper(Str::random(8)), // Example PO number
            'status' => fake()->randomElement(['received', 'pending', 'ordered']),
            'total_amount' => 0.00, // Default to 0, will be calculated later potentially
            'notes' => fake()->optional(0.5)->sentence(), // 50% chance of having notes
        ];
    }

    /**
     * Configure the model factory.
     *
     * Use afterCreating to create related PurchaseItems and update total_amount.
     *
     * @return $this
     */
    public function configure(): static // Use static return type hint
    {
        return $this->afterCreating(function (Purchase $purchase) {
            // Create between 1 and 5 purchase items for this purchase
            $itemCount = rand(1, 5);
            $totalPurchaseAmount = 0;

            // Use the PurchaseItemFactory to create related items
            // Ensure ProductFactory exists and creates products
            for ($i = 0; $i < $itemCount; $i++) {
                $item = \App\Models\PurchaseItem::factory()
                    ->for($purchase) // Associate item with this purchase
                    // ->for(\App\Models\Product::factory()) // Create a new product for each item (or get existing)
                    ->create(); // Create the item

                // Add the item's total cost to the purchase total
                $totalPurchaseAmount += $item->total_cost;

                // --- IMPORTANT: Update Product Stock ---
                // Find the product related to the item
                $product = $item->product; // Access the related product model
                if ($product) {
                    // Increment stock quantity (use increment for atomic operation)
                    $product->increment('stock_quantity', $item->quantity);
                    // Alternatively:
                    // $product->stock_quantity += $item->quantity;
                    // $product->save();
                }
            }

            // Update the total_amount on the purchase record itself
            $purchase->total_amount = $totalPurchaseAmount;
            $purchase->save();
        });
    }
}