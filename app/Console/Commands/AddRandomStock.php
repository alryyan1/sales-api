<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\Supplier;
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
                            {--min-quantity=10 : Minimum stocking units per product}
                            {--max-quantity=50 : Maximum stocking units per product}
                            {--supplier-id= : Use specific supplier ID}
                            {--warehouse-id=1 : Use specific warehouse ID}
                            {--dry-run : Show what would be done without actually creating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add random stock quantities to all products via purchase invoice';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $minQuantity = (int) $this->option('min-quantity');
        $maxQuantity = (int) $this->option('max-quantity');
        $supplierId = $this->option('supplier-id') ? (int) $this->option('supplier-id') : null;
        $warehouseId = (int) $this->option('warehouse-id');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        // Validate quantity range
        if ($minQuantity < 1 || $maxQuantity < $minQuantity) {
            $this->error('Invalid quantity range. min-quantity must be >= 1 and max-quantity must be >= min-quantity.');
            return Command::FAILURE;
        }

        try {
            DB::beginTransaction();

            // Step 1: Get or Create Supplier
            $supplier = $this->getOrCreateSupplier($supplierId);
            if (!$supplier) {
                $this->error('Failed to get or create supplier.');
                DB::rollBack();
                return Command::FAILURE;
            }
            $this->info("✓ Using supplier: {$supplier->name} (ID: {$supplier->id})");

            // Step 2: Validate Warehouse
            $warehouse = Warehouse::find($warehouseId);
            if (!$warehouse) {
                $this->error("Warehouse ID {$warehouseId} not found.");
                DB::rollBack();
                return Command::FAILURE;
            }
            $this->info("✓ Using warehouse: {$warehouse->name} (ID: {$warehouse->id})");

            // Step 3: Get All Products
            $products = Product::whereNotNull('stocking_unit_id')
                ->whereNotNull('sellable_unit_id')
                ->whereNotNull('units_per_stocking_unit')
                ->where('units_per_stocking_unit', '>', 0)
                ->get();

            if ($products->isEmpty()) {
                $this->warn('No products found with required unit information.');
                DB::rollBack();
                return Command::FAILURE;
            }

            $this->info("✓ Found {$products->count()} products to process");

            if ($dryRun) {
                $this->newLine();
                $this->info('Would create purchase with the following items:');
                $this->table(
                    ['Product', 'Stocking Units', 'Unit Cost', 'Sale Price'],
                    $products->take(10)->map(function ($product) use ($minQuantity, $maxQuantity) {
                        $qty = rand($minQuantity, $maxQuantity);
                        $unitCost = rand(50, 500);
                        $salePrice = rand(10, 100);
                        return [
                            $product->name,
                            $qty,
                            number_format($unitCost, 2),
                            number_format($salePrice, 2),
                        ];
                    })->toArray()
                );
                if ($products->count() > 10) {
                    $this->info("... and " . ($products->count() - 10) . " more products");
                }
                DB::rollBack();
                return Command::SUCCESS;
            }

            // Step 4: Create Purchase Invoice (Pending Status)
            $referenceNumber = 'RAND-STOCK-' . Carbon::now()->format('YmdHis');
            $purchase = Purchase::create([
                'warehouse_id' => $warehouseId,
                'supplier_id' => $supplier->id,
                'user_id' => null, // System command, no user
                'purchase_date' => Carbon::now()->format('Y-m-d'),
                'reference_number' => $referenceNumber,
                'status' => 'pending',
                'notes' => 'Random stock addition via command',
                'total_amount' => 0,
            ]);

            $this->info("✓ Created purchase invoice #{$purchase->id} ({$referenceNumber})");
            $this->newLine();

            // Step 5: Add Purchase Items for Each Product
            $bar = $this->output->createProgressBar($products->count());
            $bar->start();

            $itemsCreated = 0;
            $skippedProducts = [];
            $calculatedTotalAmount = 0;

            foreach ($products as $product) {
                try {
                    $quantity = rand($minQuantity, $maxQuantity);
                    $unitCost = rand(50, 500); // Cost per stocking unit
                    $salePrice = rand(10, 100); // Sale price per sellable unit
                    
                    $unitsPerStockingUnit = $product->units_per_stocking_unit ?: 1;
                    $totalSellableUnits = $quantity * $unitsPerStockingUnit;
                    $totalCost = $quantity * $unitCost;
                    $costPerSellableUnit = $unitCost / $unitsPerStockingUnit;

                    // Optional: Generate batch number
                    $batchNumber = 'BATCH-' . strtoupper(substr(md5($product->id . time() . rand()), 0, 8));

                    // Optional: Set expiry date if product has expiry
                    $expiryDate = null;
                    if ($product->has_expiry_date) {
                        $expiryDate = Carbon::now()->addMonths(rand(6, 24))->format('Y-m-d');
                    }

                    // Calculate sale_price_stocking_unit
                    $salePriceStockingUnit = $salePrice * $unitsPerStockingUnit;

                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $product->id,
                        'batch_number' => $batchNumber,
                        'quantity' => $quantity,
                        'remaining_quantity' => $totalSellableUnits,
                        'unit_cost' => $unitCost,
                        'total_cost' => $totalCost,
                        'cost_per_sellable_unit' => $costPerSellableUnit,
                        'sale_price' => $salePrice,
                        'sale_price_stocking_unit' => $salePriceStockingUnit,
                        'expiry_date' => $expiryDate,
                    ]);

                    $calculatedTotalAmount += $totalCost;
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

            // Update purchase total amount
            $purchase->total_amount = $calculatedTotalAmount;
            $purchase->save();

            $this->info("✓ Created {$itemsCreated} purchase items");
            $this->info("✓ Total purchase amount: " . number_format($calculatedTotalAmount, 2));

            // Step 6: Update Purchase Status to "received"
            $this->info("✓ Updating purchase status to 'received'...");
            
            // Reload purchase with items to ensure we have all relationships
            $purchase->load('items.product');
            
            // Update purchase status first (before updating warehouse stock)
            $purchase->status = 'received';
            $purchase->stock_added_to_warehouse = true;
            $purchase->save();
            
            // Update warehouse stock for each item (similar to PurchaseController logic)
            foreach ($purchase->items as $item) {
                $product = $item->product;
                $qtyToAdd = $item->remaining_quantity; // Quantity in sellable units

                if ($product && $warehouseId) {
                    $pivot = $product->warehouses()->where('warehouse_id', $warehouseId)->first();
                    if ($pivot) {
                        $product->warehouses()->updateExistingPivot($warehouseId, [
                            'quantity' => $pivot->pivot->quantity + $qtyToAdd
                        ]);
                    } else {
                        $product->warehouses()->attach($warehouseId, [
                            'quantity' => $qtyToAdd
                        ]);
                    }
                }
                
                // Trigger observer to update product stock_quantity
                // The observer will recalculate based on all 'received' purchase items
                $item->touch();
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
            $this->info("✅ Successfully created purchase #{$purchase->id} with {$itemsCreated} items");
            $this->info("✅ Stock has been added to all products");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ An error occurred: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Get or create a supplier
     */
    private function getOrCreateSupplier(?int $supplierId = null): ?Supplier
    {
        if ($supplierId) {
            $supplier = Supplier::find($supplierId);
            if ($supplier) {
                return $supplier;
            }
            $this->warn("Supplier ID {$supplierId} not found, creating default supplier.");
        }

        // Try to get first existing supplier
        $supplier = Supplier::first();
        if ($supplier) {
            return $supplier;
        }

        // Create default supplier
        return Supplier::create([
            'name' => 'System Supplier',
            'contact_person' => 'System',
            'email' => null,
            'phone' => null,
            'address' => null,
        ]);
    }
}

