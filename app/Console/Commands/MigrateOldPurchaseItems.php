<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Purchase;      // New Purchase model
use App\Models\PurchaseItem;   // New PurchaseItem model
use App\Models\Product;        // New Product model
use Carbon\Carbon;

class MigrateOldPurchaseItems extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:old-purchase-items {--chunk=100 : The number of records to process at a time}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrates deposit_items from the old system to purchase_items, updating product stock and purchase totals.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting old purchase items (deposit_items) migration...');

        // Fetch IDs from the new database for validation checks
        $existingPurchaseIds = Purchase::pluck('id')->all();
        $existingProductIds = Product::pluck('id')->all();

        // Use a transaction for the entire migration process for data integrity
        DB::transaction(function () use ($existingPurchaseIds, $existingProductIds) {

            $totalOldItems = DB::connection('wigdan')->table('deposit_items')->count();
            if ($totalOldItems === 0) {
                $this->warn('No items found in the old `deposit_items` table. Nothing to migrate.');
                return;
            }

            $bar = $this->output->createProgressBar($totalOldItems);
            $bar->start();

            $migratedCount = 0;
            $skippedCount = 0;
            $purchaseTotals = []; // To accumulate totals for each purchase header

            DB::connection('wigdan')->table('deposit_items')->orderBy('id')->chunkById($this->option('chunk'), function ($oldItems) use ($bar, &$migratedCount, &$skippedCount, &$purchaseTotals, $existingPurchaseIds, $existingProductIds) {

                foreach ($oldItems as $oldItem) {
                    try {
                        // --- Pre-migration Data Validation ---
                        if (!in_array($oldItem->deposit_id, $existingPurchaseIds)) {
                            $skippedCount++; $bar->advance(); continue;
                        }
                        if (!in_array($oldItem->item_id, $existingProductIds)) {
                            $skippedCount++; $bar->advance(); continue;
                        }

                        // --- Data Mapping & Calculation ---
                        $product = Product::find($oldItem->item_id);
                        if (!$product) { // Should be caught by previous check, but for safety
                             $skippedCount++; $bar->advance(); continue;
                        }

                        $unitsPerStocking = $product->units_per_stocking_unit ?: 1;
                        $totalQuantityInSellableUnits = ($oldItem->quantity + $oldItem->free_quantity) * $unitsPerStocking;
                        $costPerStockingUnit = $oldItem->cost * $unitsPerStocking; // Calculate cost per stocking unit from old cost per sellable unit

                        // --- Create new PurchaseItem ---
                        PurchaseItem::create([
                            'purchase_id' => $oldItem->deposit_id,
                            'product_id' => $oldItem->item_id,
                            'batch_number' => $oldItem->batch,
                            'quantity' => $oldItem->quantity, // Quantity in STOCKING units
                            'remaining_quantity' => $totalQuantityInSellableUnits, // Remaining in SELLABLE units
                            'unit_cost' => $costPerStockingUnit,
                            'cost_per_sellable_unit' => $oldItem->cost, // Old 'cost' was per sellable unit
                            'total_cost' => $oldItem->quantity * $costPerStockingUnit,
                            'sale_price' => $oldItem->sell_price, // Assuming this is per sellable unit
                            'expiry_date' => $oldItem->expire ? Carbon::parse($oldItem->expire) : null,
                            'created_at' => Carbon::parse($oldItem->created_at),
                            'updated_at' => Carbon::parse($oldItem->updated_at),
                        ]);

                        // Accumulate total cost for the parent purchase header
                        $purchaseTotals[$oldItem->deposit_id] = ($purchaseTotals[$oldItem->deposit_id] ?? 0) + ($oldItem->quantity * $costPerStockingUnit);

                        // Update product total stock - OBSERVER SHOULD HANDLE THIS.
                        // If not using an observer, you would do it here:
                        // $product->increment('stock_quantity', $totalQuantityInSellableUnits);

                        $migratedCount++;
                    } catch (\Exception $e) {
                        $this->error("\nFailed to migrate deposit_item with ID {$oldItem->id}");
                        $this->error("Error: " . $e->getMessage());
                        Log::error("PurchaseItem Migration Failed: Old ID={$oldItem->id}, Error={$e->getMessage()}");
                    }
                    $bar->advance();
                } // end foreach
            }); // end chunkById

            $bar->finish();
            $this->info("\n\nPurchase items processed. Now updating purchase totals and product stock...");

            // --- Update Purchase Totals ---
            $bar2 = $this->output->createProgressBar(count($purchaseTotals));
            $bar2->start();
            foreach ($purchaseTotals as $purchaseId => $total) {
                Purchase::where('id', $purchaseId)->update(['total_amount' => $total]);
                $bar2->advance();
            }
            $bar2->finish();
            $this->info("\nPurchase totals updated.");

            // --- Recalculate all product stocks (safer than incrementing) ---
            $this->info("Recalculating all product stock quantities based on migrated batches...");
            $productsToUpdate = Product::whereHas('purchaseItems')->get();
            $bar3 = $this->output->createProgressBar($productsToUpdate->count());
            $bar3->start();
            foreach($productsToUpdate as $product) {
                // This logic is the same as the PurchaseItemObserver
                $totalStock = $product->purchaseItems()->sum('remaining_quantity');
                $product->stock_quantity = $totalStock;
                $product->saveQuietly(); // Use quietly to not trigger other events
                $bar3->advance();
            }
            $bar3->finish();
            $this->info("\nProduct stock quantities updated.");


            $this->info("\nOld purchase items data migration complete!");
            $this->info("Successfully migrated: {$migratedCount} items.");
            $this->warn("Skipped (missing product/purchase): {$skippedCount} items.");
        }); // end DB::transaction

        return self::SUCCESS;
    }
}