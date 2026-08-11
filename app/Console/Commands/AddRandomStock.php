<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;

class AddRandomStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:add-random
                            {--min-quantity=10 : Minimum stocking units to add per product}
                            {--max-quantity=50 : Maximum stocking units to add per product}
                            {--warehouse-id=1 : Use specific warehouse ID}
                            {--user-id= : User ID to record the inventory count under (defaults to the first user)}
                            {--dry-run : Show what would be done without actually creating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add random stock quantities to all products via an approved inventory count';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $minQuantity = (int) $this->option('min-quantity');
        $maxQuantity = (int) $this->option('max-quantity');
        $warehouseId = (int) $this->option('warehouse-id');
        $userId = $this->option('user-id') ? (int) $this->option('user-id') : null;

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        // Validate quantity range
        if ($minQuantity < 1 || $maxQuantity < $minQuantity) {
            $this->error('Invalid quantity range. min-quantity must be >= 1 and max-quantity must be >= min-quantity.');
            return Command::FAILURE;
        }

        // Step 1: Validate Warehouse
        $warehouse = Warehouse::find($warehouseId);
        if (!$warehouse) {
            $this->error("Warehouse ID {$warehouseId} not found.");
            return Command::FAILURE;
        }
        $this->info("✓ Using warehouse: {$warehouse->name} (ID: {$warehouse->id})");

        // Step 2: Resolve User (inventory counts require a user)
        $user = $userId ? User::find($userId) : User::first();
        if (!$user) {
            $this->error('No user found to record the inventory count under. Pass --user-id.');
            return Command::FAILURE;
        }
        $this->info("✓ Recording count under user: {$user->name} (ID: {$user->id})");

        // Step 3: Get All Products
        $products = Product::whereNotNull('stocking_unit_id')
            ->whereNotNull('sellable_unit_id')
            ->whereNotNull('units_per_stocking_unit')
            ->where('units_per_stocking_unit', '>', 0)
            ->get();

        if ($products->isEmpty()) {
            $this->warn('No products found with required unit information.');
            return Command::FAILURE;
        }

        $this->info("✓ Found {$products->count()} products to process");

        if ($dryRun) {
            $this->newLine();
            $this->info('Would create an inventory count with the following adjustments:');
            $this->table(
                ['Product', 'Current Stock', 'Added Qty', 'New Stock'],
                $products->take(10)->map(function ($product) use ($warehouseId, $minQuantity, $maxQuantity) {
                    $current = $product->getWarehouseStock($warehouseId);
                    $added = rand($minQuantity, $maxQuantity);

                    return [$product->name, $current, $added, $current + $added];
                })->toArray()
            );
            if ($products->count() > 10) {
                $this->info("... and " . ($products->count() - 10) . " more products");
            }
            return Command::SUCCESS;
        }

        try {
            DB::beginTransaction();

            // Step 4: Create Inventory Count (draft)
            $inventoryCount = InventoryCount::create([
                'warehouse_id' => $warehouseId,
                'user_id' => $user->id,
                'count_date' => Carbon::now()->format('Y-m-d'),
                'status' => 'draft',
                'notes' => 'Random stock addition via command',
            ]);

            $this->info("✓ Created inventory count #{$inventoryCount->id}");
            $this->newLine();

            // Step 5: Add a Count Item per Product (actual = current stock + random addition)
            $bar = $this->output->createProgressBar($products->count());
            $bar->start();

            $itemsCreated = 0;
            $skippedProducts = [];

            foreach ($products as $product) {
                try {
                    $expectedQuantity = $product->getWarehouseStock($warehouseId);
                    $addedQuantity = rand($minQuantity, $maxQuantity);

                    InventoryCountItem::create([
                        'inventory_count_id' => $inventoryCount->id,
                        'product_id' => $product->id,
                        'expected_quantity' => $expectedQuantity,
                        'actual_quantity' => $expectedQuantity + $addedQuantity,
                    ]);

                    $itemsCreated++;
                } catch (\Exception $e) {
                    $skippedProducts[] = [
                        'product' => $product->name,
                        'reason' => $e->getMessage(),
                    ];
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $this->info("✓ Created {$itemsCreated} inventory count items");

            // Step 6: Mark the count completed, then approve it (this is what
            // actually writes the new quantities to product_warehouse — the SSOT).
            $inventoryCount->update([
                'status' => 'completed',
                'started_at' => Carbon::now(),
                'completed_at' => Carbon::now(),
            ]);

            $this->info('✓ Approving inventory count...');
            $inventoryCount->load('items.product');
            $approved = $inventoryCount->approve($user->id);

            if (!$approved) {
                throw new \RuntimeException('Failed to approve inventory count.');
            }

            // Show skipped products if any
            if (!empty($skippedProducts)) {
                $this->newLine();
                $this->warn('⚠ Skipped products:');
                foreach ($skippedProducts as $skipped) {
                    $this->warn("  - {$skipped['product']}: {$skipped['reason']}");
                }
            }

            DB::commit();

            $this->newLine();
            $this->info("✅ Successfully created and approved inventory count #{$inventoryCount->id} with {$itemsCreated} items");
            $this->info("✅ Stock has been added to all products");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ An error occurred: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
