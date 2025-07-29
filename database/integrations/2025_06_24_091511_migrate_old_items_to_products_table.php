<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Support\Str;


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
        $oldItemsConnection = 'one_care'; // Connection to one_care database
        $oldItemsTableName = 'items';
        // --- END CONFIGURATION ---

        // First, ensure we have the necessary units and categories
        $this->ensureRequiredUnits();
        $this->ensureRequiredCategories();

        // Fetch items from the old table in chunks to manage memory
        $query = DB::connection($oldItemsConnection)->table($oldItemsTableName);

        $query->orderBy('id')->chunk(200, function ($oldItems) use ($oldItemsConnection) {
            $productsToInsert = [];
            
            foreach ($oldItems as $oldItem) {
                // Basic Transformation Logic
                $productName = $oldItem->market_name ?: $oldItem->name; // Prioritize market_name

                // Skip if product name is empty or if product already exists (by name or SKU)
                if (empty($productName)) {
                    continue;
                }
                
                if (Product::where('name', $productName)->exists() || 
                    ($oldItem->barcode && Product::where('sku', $oldItem->barcode)->exists())) {
                    continue;
                }

                // Unit Handling - Map to existing units or create new ones
                $sellableUnitId = $this->getOrCreateUnit($oldItem->unit ?: 'Piece', 'sellable');
                $stockingUnitId = $this->getOrCreateUnit('Box', 'stocking'); // Default to Box
                
                // Calculate units per stocking unit from pack_size
                $unitsPerStockingUnit = 1;
                if (!empty($oldItem->pack_size) && is_numeric($oldItem->pack_size) && (int)$oldItem->pack_size > 0) {
                    $unitsPerStockingUnit = (int)$oldItem->pack_size;
                }

                // Category Mapping
                $categoryId = null;
                if (!empty($oldItem->drug_category_id)) {
                    $categoryId = $this->getOrCreateCategory($oldItem->drug_category_id, $oldItemsConnection);
                }

                // Scientific name from sc_name
                $scientificName = !empty($oldItem->sc_name) ? $oldItem->sc_name : null;

                // Stock quantity from initial_balance
                $stockQuantity = (int)($oldItem->initial_balance ?? 0);

                // Stock alert level - use a default or calculate based on require_amount
                $stockAlertLevel = !empty($oldItem->require_amount) ? (int)$oldItem->require_amount : 10;

                $productsToInsert[] = [
                    'name' => $productName,
                    'scientific_name' => $scientificName,
                    'sku' => $oldItem->barcode ?: ('SKU-' . Str::upper(Str::random(8))),
                    'description' => $this->buildDescription($oldItem),
                    'category_id' => $categoryId,
                    'stocking_unit_id' => $stockingUnitId,
                    'sellable_unit_id' => $sellableUnitId,
                    'units_per_stocking_unit' => $unitsPerStockingUnit,
                    'stock_quantity' => $stockQuantity,
                    'stock_alert_level' => $stockAlertLevel,
                    'created_at' => $oldItem->created_at ?: now(),
                    'updated_at' => $oldItem->updated_at ?: now(),
                ];
            }

            if (!empty($productsToInsert)) {
                Product::insert($productsToInsert);
            }
        });
    }

    /**
     * Ensure required units exist in the system
     */
    private function ensureRequiredUnits()
    {
        $defaultUnits = [
            ['name' => 'Piece', 'type' => 'sellable'],
            ['name' => 'Item', 'type' => 'sellable'],
            ['name' => 'Box', 'type' => 'stocking'],
            ['name' => 'Carton', 'type' => 'stocking'],
            ['name' => 'Pack', 'type' => 'stocking'],
            ['name' => 'Bottle', 'type' => 'sellable'],
            ['name' => 'Tablet', 'type' => 'sellable'],
            ['name' => 'Capsule', 'type' => 'sellable'],
            ['name' => 'Ampoule', 'type' => 'sellable'],
            ['name' => 'Vial', 'type' => 'sellable'],
        ];

        foreach ($defaultUnits as $unit) {
            Unit::firstOrCreate(
                ['name' => $unit['name'], 'type' => $unit['type']],
                [
                    'description' => ucfirst($unit['type']) . ' unit: ' . $unit['name'],
                    'is_active' => true
                ]
            );
        }
    }

    /**
     * Ensure required categories exist
     */
    private function ensureRequiredCategories()
    {
        // Create a default category if none exists
        if (Category::count() === 0) {
            Category::create([
                'name' => 'General',
                'description' => 'General category for migrated products'
            ]);
        }
    }

    /**
     * Get or create a unit
     */
    private function getOrCreateUnit($unitName, $type)
    {
        if (empty($unitName)) {
            $unitName = $type === 'sellable' ? 'Piece' : 'Box';
        }

        $unit = Unit::firstOrCreate(
            ['name' => $unitName, 'type' => $type],
            [
                'description' => ucfirst($type) . ' unit: ' . $unitName,
                'is_active' => true
            ]
        );

        return $unit->id;
    }

    /**
     * Get or create a category based on old drug_category_id
     */
    private function getOrCreateCategory($oldCategoryId, $connection)
    {
        try {
            // Try to get category name from old database
            $oldCategory = DB::connection($connection)
                ->table('drug_categories')
                ->where('id', $oldCategoryId)
                ->first();

            if ($oldCategory && !empty($oldCategory->name)) {
                $category = Category::firstOrCreate(
                    ['name' => $oldCategory->name],
                    [
                        'description' => 'Migrated from old system - Drug Category: ' . $oldCategory->name
                    ]
                );
                return $category->id;
            }
        } catch (\Exception $e) {
            // $this->command->warn("Could not fetch category from old database: " . $e->getMessage()); // Removed command output
        }

        // Fallback to default category
        $defaultCategory = Category::where('name', 'General')->first();
        return $defaultCategory ? $defaultCategory->id : null;
    }

    /**
     * Build description from multiple fields
     */
    private function buildDescription($oldItem)
    {
        $descriptionParts = [];

        if (!empty($oldItem->sc_name)) {
            $descriptionParts[] = "Scientific Name: " . $oldItem->sc_name;
        }

        if (!empty($oldItem->active1)) {
            $descriptionParts[] = "Active 1: " . $oldItem->active1;
        }

        if (!empty($oldItem->active2)) {
            $descriptionParts[] = "Active 2: " . $oldItem->active2;
        }

        if (!empty($oldItem->active3)) {
            $descriptionParts[] = "Active 3: " . $oldItem->active3;
        }

        if (!empty($oldItem->batch)) {
            $descriptionParts[] = "Batch: " . $oldItem->batch;
        }

        if (!empty($oldItem->pack_size)) {
            $descriptionParts[] = "Pack Size: " . $oldItem->pack_size;
        }

        if (!empty($oldItem->strips)) {
            $descriptionParts[] = "Strips: " . $oldItem->strips;
        }

        if (!empty($oldItem->tests)) {
            $descriptionParts[] = "Tests: " . $oldItem->tests;
        }

        if (!empty($oldItem->expire)) {
            $descriptionParts[] = "Expiry: " . $oldItem->expire;
        }

        return !empty($descriptionParts) ? implode("\n", $descriptionParts) : null;
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
        // $this->command->info("Rolling back MigrateOldItemsToProductsTable: No automatic data deletion implemented. Please handle manually if needed."); // Removed command output
        
        // If you want to delete migrated products, uncomment the line below:
        // Product::where('sku', 'like', 'SKU-%')->delete(); // Delete products with generated SKUs
    }
}