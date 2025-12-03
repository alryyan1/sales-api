<?php

namespace Database\Factories;

use App\Models\Sale;     // Correct model
use App\Models\Client;   // Need Client
use App\Models\User;     // Need User
use App\Models\Product;  // Need Product for stock check/update logic
use App\Models\SaleItem; // Need SaleItem factory within configure()
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;     // For potential transactions
use Illuminate\Support\Facades\Log;    // For logging issues
use Illuminate\Support\Str;    // For invoice number

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sale>
 */
class SaleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Sale::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $client = Client::inRandomOrder()->first() ?? Client::factory()->create();
        $user = User::inRandomOrder()->first() ?? User::factory()->create();

        return [
            'client_id' => $client->id,
            'user_id' => $user->id,
            'sale_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'invoice_number' => 'INV-' . Str::upper(Str::random(8)),
            'notes' => fake()->optional(0.4)->sentence(),
        ];
    }

    /**
     * Configure the model factory.
     *
     * Create SaleItems after Sale creation and handle stock deduction.
     *
     * @return $this
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Sale $sale) {
            // Create between 1 and 4 sale items
            $itemCount = rand(1, 4);
            $totalSaleAmount = 0;
            $itemsData = []; // Store item data before creating

            // Use a transaction for safety when updating stock
            try {
                DB::transaction(function () use ($sale, $itemCount, &$totalSaleAmount, &$itemsData) { // Pass vars by reference

                    // Find products with enough stock
                    $availableProducts = Product::where('stock_quantity', '>', 0)
                                                ->inRandomOrder()
                                                ->limit($itemCount * 2) // Fetch more than needed initially
                                                ->get();

                    if ($availableProducts->isEmpty()) {
                         Log::warning("SaleFactory: No products with stock available to create sale items for Sale ID: {$sale->id}");
                         // Optionally delete the sale header if no items can be added
                         // $sale->delete();
                         // throw new \Exception("Cannot create sale items, no products in stock."); // Or throw exception
                         return; // Exit transaction if no products available
                    }

                    $createdItemCount = 0;
                    foreach ($availableProducts as $product) {
                        if ($createdItemCount >= $itemCount) break; // Stop if we created enough items

                        // Ensure stock isn't accidentally negative from previous iterations if not using lockForUpdate
                        $currentStock = $product->stock_quantity;
                        if ($currentStock <= 0) continue; // Skip if stock became 0

                        // Quantity to sell: between 1 and min(5, current stock)
                        $quantity = rand(1, min(5, $currentStock));

                        $unitPrice = $product->sale_price; // Use product's sale price
                        $totalCost = $quantity * $unitPrice;

                        // --- Decrement Product Stock ---
                        // Using decrement is generally safer for concurrency
                        $product->decrement('stock_quantity', $quantity);
                        // Log::info("Stock decremented for product {$product->id}. Sold: {$quantity}. New Stock: {$product->fresh()->stock_quantity}");

                        // Store item data to create after loop (or create directly)
                        $itemsData[] = [
                            'product_id' => $product->id,
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'total_price' => $totalCost,
                        ];

                        $totalSaleAmount += $totalCost;
                        $createdItemCount++;

                         // Reload product to ensure next iteration gets fresh stock count if not locking
                         // $product->refresh(); // uncomment if not using lockForUpdate and experiencing issues
                    }

                    // Create SaleItems using the collected data and relationship
                    if (!empty($itemsData)) {
                         $sale->items()->createMany($itemsData);
                    } else if ($createdItemCount === 0) {
                         // If after checking all available products, none could be added (e.g. stock became 0 concurrently)
                         Log::warning("SaleFactory: Failed to add any items with stock for Sale ID: {$sale->id}");
                         // Rollback will happen automatically if exception is thrown
                         // throw new \Exception("Cannot create sale items, no products with sufficient stock found.");
                         return; // Exit transaction
                    }


                    // No need to update header totals; totals are now derived from items and payments.

                }); // End DB::transaction

            } catch (\Throwable $e) {
                Log::error("SaleFactory: Transaction failed for Sale ID: {$sale->id}. Error: " . $e->getMessage());
                 // Optionally delete the sale header if transaction failed severely
                 // $sale->delete();
            }
        });
    }
}