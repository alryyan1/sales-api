<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;      // For database operations
use Illuminate\Support\Facades\Schema;  // Though not strictly needed for data migration
use App\Models\Product; // Your new Product model
use App\Models\Category; // If you want to try mapping categories
use Illuminate\Support\Str; // For SKU generation if barcode is null

class MigrateOldItemsToProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // --- CONFIGURATION ---
        // Change this if your old items table is on a different connection
        $oldItemsConnection = 'wigdan'; // null for default, or 'other_db' for your defined connection
        $oldItemsTableName = 'items';
        // --- END CONFIGURATION ---

        // $this->command->info("Starting migration of old '{$oldItemsTableName}' to 'products' table...");

        // Fetch items from the old table in chunks to manage memory
        $query = $oldItemsConnection ? DB::connection($oldItemsConnection)->table($oldItemsTableName) : DB::table($oldItemsTableName);

        $query->orderBy('id')->chunk(200, function ($oldItems) {
            $productsToInsert = [];
            foreach ($oldItems as $oldItem) {
                // Basic Transformation Logic
                $productName = $oldItem->market_name ?: $oldItem->name; // Prioritize market_name

                // Skip if product name is empty or if product already exists (by name or SKU)
                if (empty($productName)) {
                    // $this->command->warn("Skipping old item ID {$oldItem->id} due to empty market_name/name.");
                    continue;
                }
                if (Product::where('name', $productName)->exists() || ($oldItem->barcode && Product::where('sku', $oldItem->barcode)->exists())) {
                    //  $this->command->warn("Product '{$productName}' or SKU '{$oldItem->barcode}' already exists. Skipping old item ID {$oldItem->id}.");
                     continue;
                }


                // Unit Handling (This is an assumption, adjust based on your old data)
                // Assuming 'unit' is the sellable unit and 'pack_size' implies units per stocking unit
                $sellableUnitName = $oldItem->unit ?: 'Piece'; // Default to 'Piece'
                $stockingUnitName = 'Box'; // Default, or derive if possible from old data
                $unitsPerStockingUnit = 1;
                if (!empty($oldItem->pack_size) && is_numeric($oldItem->pack_size) && (int)$oldItem->pack_size > 0) {
                    $unitsPerStockingUnit = (int)$oldItem->pack_size;
                    // You might need more sophisticated logic if pack_size is like "1x4" or "4 pieces"
                }
                // If 'unit' itself represents a pack (e.g., 'Box of 10'), you need to parse it.
                // For simplicity, we assume old 'unit' is the sellable unit name.


                // Category Mapping (Optional, basic example)
                $categoryId = null;
                if (!empty($oldItem->drug_category_id)) {
                    // Try to find category by old ID if you have a mapping, or by name if you seed categories first.
                    // For this example, we'll assume you might seed categories with matching names or IDs.
                    // $category = Category::where('old_system_id', $oldItem->drug_category_id)->first();
                    // if ($category) {
                    //     $categoryId = $category->id;
                    // }
                }

                $productsToInsert[] = [
                    'name' => $productName,
                    'sku' => $oldItem->barcode ?: ('SKU-' . Str::upper(Str::random(8))), // Use barcode or generate
                    'description' => $oldItem->sc_name ?: null, // Or use another description field if available
                    'category_id' => $categoryId, // Set if mapped

                    // Stock quantity is initial_balance, assumed to be in sellable units
                    'stock_quantity' => (int)($oldItem->initial_balance ?? 0),
                    // Stock alert level - use a default or derive if possible
                    'stock_alert_level' => 10, // Default, adjust as needed

                    // New unit fields
                    'stocking_unit_name' => $stockingUnitName,
                    'sellable_unit_name' => $sellableUnitName,
                    'units_per_stocking_unit' => $unitsPerStockingUnit,

                    'created_at' => $oldItem->created_at ?: now(),
                    'updated_at' => $oldItem->updated_at ?: now(),
                ];
            }

            if (!empty($productsToInsert)) {
                Product::insert($productsToInsert); // Bulk insert for efficiency
                // $this->command->info("Inserted " . count($productsToInsert) . " products.");
            }
        });

        // $this->command->info("Finished migrating old '{$oldItemsTableName}' to 'products' table.");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // This migration is for data. Reversing it would mean deleting the migrated products.
        // It's generally safer to not automatically delete data in a rollback for such migrations.
        // If you need to revert, you might truncate the products table or delete based on some criteria.
        // $this->command->info("Rolling back MigrateOldItemsToProductsTable: No automatic data deletion implemented. Please handle manually if needed.");
        // Example: Product::where('migrated_from_old_system', true)->delete(); // If you add a flag
    }
}