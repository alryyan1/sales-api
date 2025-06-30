<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Purchase; // Your NEW Purchase model
use App\Models\Supplier; // Your NEW Supplier model
use App\Models\User;     // Your NEW User model
use Carbon\Carbon;

class MigrateOldPurchases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:old-purchases {--chunk=100 : The number of records to process at a time}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate purchase headers (deposits) data from the old system database to the new one.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting old purchase (deposits) data migration...');

        // Track IDs of suppliers and users that exist in the NEW database to avoid multiple lookups
        $existingSupplierIds = Supplier::pluck('id')->all();
        $existingUserIds = User::pluck('id')->all();

        $totalOldPurchases = DB::connection('wigdan')->table('deposits')->count();
        if ($totalOldPurchases === 0) {
            $this->warn('No purchase records (deposits) found in the old database. Nothing to migrate.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalOldPurchases);
        $bar->start();

        $migratedCount = 0;
        $skippedCount = 0;
        $skippedReason = [];

        DB::connection('wigdan')->table('deposits')->orderBy('id')->chunkById($this->option('chunk'), function ($oldPurchases) use ($bar, &$migratedCount, &$skippedCount, &$skippedReason, $existingSupplierIds, $existingUserIds) {

            foreach ($oldPurchases as $oldPurchase) {
                try {
                    // --- Pre-migration Data Validation ---

                    // 1. Check if the supplier_id from the old DB exists in the new DB
                    if (!in_array($oldPurchase->supplier_id, $existingSupplierIds)) {
                        $reason = "Supplier ID {$oldPurchase->supplier_id} not found in new DB.";
                        $skippedReason[$reason] = ($skippedReason[$reason] ?? 0) + 1;
                        $skippedCount++;
                        $bar->advance();
                        continue;
                    }

                    // 2. Check if the user_id exists (if not null)
                    if ($oldPurchase->user_id && !in_array($oldPurchase->user_id, $existingUserIds)) {
                        $reason = "User ID {$oldPurchase->user_id} not found in new DB.";
                        $skippedReason[$reason] = ($skippedReason[$reason] ?? 0) + 1;
                        $skippedCount++;
                        $bar->advance();
                        continue;
                    }

                    // 3. Check for duplicates in the new system based on reference number
                    if (Purchase::where('reference_number', $oldPurchase->bill_number)->exists()) {
                        $reason = "Reference #{$oldPurchase->bill_number} already exists.";
                        $skippedReason[$reason] = ($skippedReason[$reason] ?? 0) + 1;
                        $skippedCount++;
                        $bar->advance();
                        continue;
                    }

                    // --- Data Mapping ---

                    // Map status
                    $status = 'pending'; // Default
                    if ($oldPurchase->complete == 1 && $oldPurchase->paid == 1) {
                        $status = 'received';
                    } elseif ($oldPurchase->complete == 0 && $oldPurchase->paid == 1) {
                        $status = 'ordered';
                    }

                    // Build notes from extra old data
                    $notes = "Migrated from old system.\n";
                    $notes .= "Old Payment Method: {$oldPurchase->payment_method}\n";
                    $notes .= "Old Discount: {$oldPurchase->discount}\n";
                    $notes .= "Old VAT Sell: {$oldPurchase->vat_sell}\n";
                    $notes .= "Old VAT Cost: {$oldPurchase->vat_cost}\n";
                    $notes .= "Old `is_locked` flag: {$oldPurchase->is_locked}\n";
                    $notes .= "Old `showAll` flag: {$oldPurchase->showAll}";


                    // Create the new purchase record
                    Purchase::create([
                        'supplier_id' => $oldPurchase->supplier_id,
                        'user_id' => $oldPurchase->user_id, // Can be null if it was null in old DB
                        'reference_number' => $oldPurchase->bill_number,
                        'purchase_date' => Carbon::parse($oldPurchase->bill_date),
                        'status' => $status,
                        'total_amount' => 0.00, // Cannot be calculated from old data
                        'notes' => $notes,
                        // Preserve original timestamps
                        'created_at' => Carbon::parse($oldPurchase->created_at),
                        'updated_at' => Carbon::parse($oldPurchase->updated_at),
                    ]);

                    $migratedCount++;

                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate purchase with old ID {$oldPurchase->id} (Ref: {$oldPurchase->bill_number})");
                    $this->error("Error: " . $e->getMessage());
                    Log::error("Purchase Migration Failed: Old ID={$oldPurchase->id}, Ref={$oldPurchase->bill_number}, Error={$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();

        $this->info("\n\nOld purchase data migration complete!");
        $this->info("Successfully migrated: {$migratedCount} purchase headers.");
        $this->warn("Skipped records: {$skippedCount}");
        foreach ($skippedReason as $reason => $count) {
             $this->warn("- {$reason}: {$count} times");
        }

        return self::SUCCESS;
    }
}