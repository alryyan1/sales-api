<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;

class MigrateOldDeposits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:old-deposits {--chunk=100 : The number of records to process at a time} {--dry-run : Show what would be migrated without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate deposits data from the old system database to the new purchases table.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting deposits to purchases migration...');

        // Check if we're doing a dry run
        $isDryRun = $this->option('dry-run');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No data will be actually migrated');
        }

        // Use raw SQL to access the one_care database
        $totalOldDeposits = DB::select('SELECT COUNT(*) as count FROM one_care.deposits')[0]->count;
        if ($totalOldDeposits === 0) {
            $this->warn('No deposits found in the old database. Nothing to migrate.');
            return 0;
        }

        $this->info("Found {$totalOldDeposits} deposits to migrate.");

        // Use a progress bar for better user feedback
        $bar = $this->output->createProgressBar($totalOldDeposits);
        $bar->start();

        $migratedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        // Process in chunks to avoid memory issues with large datasets
        $offset = 0;
        $chunkSize = $this->option('chunk');
        
        while ($offset < $totalOldDeposits) {
            $oldDeposits = DB::select("SELECT * FROM one_care.deposits ORDER BY id LIMIT {$chunkSize} OFFSET {$offset}");
            
            foreach ($oldDeposits as $oldDeposit) {
                try {
                    // Check if a purchase with the same reference number already exists
                    $exists = Purchase::where('reference_number', $oldDeposit->bill_number)->exists();

                    if ($exists) {
                        $this->line("\nSkipping existing purchase: {$oldDeposit->bill_number}");
                        $skippedCount++;
                        $bar->advance();
                        continue;
                    }

                    // Map supplier_id - we need to find the corresponding supplier in the new system
                    $newSupplierId = $this->mapSupplierId($oldDeposit->supplier_id);
                    if (!$newSupplierId) {
                        $this->warn("\nSkipping deposit {$oldDeposit->id}: Supplier not found in new system");
                        $skippedCount++;
                        $bar->advance();
                        continue;
                    }

                    // Map user_id
                    $newUserId = $this->mapUserId($oldDeposit->user_id);

                    // Determine status based on complete and paid flags
                    $status = $this->determinePurchaseStatus($oldDeposit->complete, $oldDeposit->paid);

                    // Calculate total amount from deposit items
                    $totalAmount = $this->calculateTotalAmount($oldDeposit->id);

                    // Handle invalid dates
                    $purchaseDate = $this->validateDate($oldDeposit->bill_date);

                    if (!$isDryRun) {
                        // Create the new purchase
                        $purchase = Purchase::create([
                            'supplier_id' => $newSupplierId,
                            'user_id' => $newUserId,
                            'purchase_date' => $purchaseDate,
                            'reference_number' => $oldDeposit->bill_number,
                            'status' => $status,
                            'total_amount' => $totalAmount,
                            'notes' => "Migrated from old deposits system. Payment method: {$oldDeposit->payment_method}, Discount: {$oldDeposit->discount}",
                            'created_at' => Carbon::parse($oldDeposit->created_at),
                            'updated_at' => Carbon::parse($oldDeposit->updated_at),
                        ]);

                        // Migrate deposit items to purchase items
                        $this->migrateDepositItems($oldDeposit->id, $purchase->id, $isDryRun);
                    }

                    $migratedCount++;
                    
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate deposit with ID {$oldDeposit->id}: {$oldDeposit->bill_number}");
                    $this->error("Error: " . $e->getMessage());
                    Log::error("Deposit Migration Failed: ID={$oldDeposit->id}, Bill={$oldDeposit->bill_number}, Error={$e->getMessage()}");
                    $errorCount++;
                }

                $bar->advance();
            }
            
            $offset += $chunkSize; // Increment offset for the next chunk
        }

        $bar->finish();

        $this->info("\n\nDeposits to purchases migration complete!");
        $this->info("Successfully migrated: {$migratedCount} deposits.");
        $this->warn("Skipped (already exist): {$skippedCount} deposits.");
        if ($errorCount > 0) {
            $this->error("Failed to migrate: {$errorCount} deposits.");
        }

        if ($isDryRun) {
            $this->info("This was a dry run. No actual data was migrated.");
        }

        return 0;
    }

    /**
     * Map old supplier ID to new supplier ID
     */
    private function mapSupplierId($oldSupplierId)
    {
        // Get the old supplier name
        $oldSupplier = DB::select("SELECT name FROM one_care.suppliers WHERE id = ?", [$oldSupplierId]);
        if (empty($oldSupplier)) {
            return null;
        }

        $oldSupplierName = $oldSupplier[0]->name;

        // Find matching supplier in new system by name
        $newSupplier = Supplier::where('name', $oldSupplierName)->first();
        return $newSupplier ? $newSupplier->id : null;
    }

    /**
     * Map old user ID to new user ID
     */
    private function mapUserId($oldUserId)
    {
        if (!$oldUserId) {
            return null;
        }

        // Get the old user name
        $oldUser = DB::select("SELECT name FROM one_care.users WHERE id = ?", [$oldUserId]);
        if (empty($oldUser)) {
            return null;
        }

        $oldUserName = $oldUser[0]->name;

        // Find matching user in new system by name only
        $newUser = User::where('name', $oldUserName)->first();
        
        return $newUser ? $newUser->id : null;
    }

    /**
     * Determine purchase status based on complete and paid flags
     */
    private function determinePurchaseStatus($complete, $paid)
    {
        if ($complete == 1) {
            return 'received';
        } elseif ($paid == 1) {
            return 'ordered';
        } else {
            return 'pending';
        }
    }

    /**
     * Calculate total amount from deposit items
     */
    private function calculateTotalAmount($depositId)
    {
        $result = DB::select("SELECT SUM(cost * quantity) as total FROM one_care.deposit_items WHERE deposit_id = ?", [$depositId]);
        return $result[0]->total ?? 0;
    }

    /**
     * Migrate deposit items to purchase items
     */
    private function migrateDepositItems($oldDepositId, $newPurchaseId, $isDryRun)
    {
        $depositItems = DB::select("SELECT * FROM one_care.deposit_items WHERE deposit_id = ?", [$oldDepositId]);
        
        foreach ($depositItems as $oldItem) {
            try {
                // Map item_id to product_id
                $newProductId = $this->mapProductId($oldItem->item_id);
                if (!$newProductId) {
                    $this->warn("Skipping deposit item {$oldItem->id}: Product not found in new system");
                    continue;
                }

                // Calculate total cost
                $totalCost = $oldItem->cost * $oldItem->quantity;

                // Handle invalid expiry dates
                $expiryDate = $this->validateDate($oldItem->expire);

                if (!$isDryRun) {
                    // Create the new purchase item
                    PurchaseItem::create([
                        'purchase_id' => $newPurchaseId,
                        'product_id' => $newProductId,
                        'batch_number' => $oldItem->batch,
                        'quantity' => $oldItem->quantity,
                        'remaining_quantity' => $oldItem->quantity, // Initially same as quantity
                        'unit_cost' => $oldItem->cost,
                        'total_cost' => $totalCost,
                        'sale_price' => $oldItem->sell_price,
                        'expiry_date' => $expiryDate,
                        'cost_per_sellable_unit' => $oldItem->cost, // Assuming 1:1 ratio initially
                        'created_at' => Carbon::parse($oldItem->created_at),
                        'updated_at' => Carbon::parse($oldItem->updated_at),
                    ]);
                }

            } catch (\Exception $e) {
                $this->error("Failed to migrate deposit item {$oldItem->id}: " . $e->getMessage());
                Log::error("Deposit Item Migration Failed: ID={$oldItem->id}, Error={$e->getMessage()}");
            }
        }
    }

    /**
     * Map old item ID to new product ID
     */
    private function mapProductId($oldItemId)
    {
        // Get the old item name
        $oldItem = DB::select("SELECT name, market_name FROM one_care.items WHERE id = ?", [$oldItemId]);
        if (empty($oldItem)) {
            return null;
        }

        $oldItemName = $oldItem[0]->market_name ?: $oldItem[0]->name;

        // Find matching product in new system by name
        $newProduct = Product::where('name', $oldItemName)->first();
        return $newProduct ? $newProduct->id : null;
    }

    /**
     * Validate and return a date string.
     * If the date is invalid, return today's date.
     */
    private function validateDate($dateString)
    {
        // Handle MySQL zero dates and other invalid formats
        if (empty($dateString) || $dateString === '0000-00-00' || $dateString === '0000-00-00 00:00:00') {
            Log::warning("Invalid date found in old deposits: {$dateString}. Using today's date instead.");
            return Carbon::today()->format('Y-m-d');
        }

        try {
            $carbonDate = Carbon::parse($dateString);
            // Check if the parsed date is valid (not in the distant past)
            if ($carbonDate->year < 1900) {
                Log::warning("Date too old found in old deposits: {$dateString}. Using today's date instead.");
                return Carbon::today()->format('Y-m-d');
            }
            return $carbonDate->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning("Invalid date found in old deposits: {$dateString}. Using today's date instead.");
            return Carbon::today()->format('Y-m-d');
        }
    }
}
