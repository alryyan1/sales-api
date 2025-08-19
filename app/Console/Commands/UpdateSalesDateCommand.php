<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Sale;
use Carbon\Carbon;

class UpdateSalesDateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sales:update-date {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update sales table to set sale_date equal to created_at';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        
        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting sales date update process...');

        try {
            // Get total count of sales
            $totalSales = Sale::count();
            $this->info("Total sales records found: {$totalSales}");

            if ($totalSales === 0) {
                $this->warn('No sales records found in the database.');
                return 0;
            }

            // Get sales where sale_date is different from created_at date
            $salesToUpdate = Sale::whereRaw('DATE(sale_date) != DATE(created_at)')
                ->orWhereNull('sale_date')
                ->get();

            $this->info("Sales records that need updating: {$salesToUpdate->count()}");

            if ($salesToUpdate->count() === 0) {
                $this->info('✅ All sales records already have sale_date equal to created_at date.');
                return 0;
            }

            // Show preview of changes
            $this->info("\n📋 Preview of changes:");
            $this->table(
                ['ID', 'Current sale_date', 'Current created_at', 'New sale_date'],
                $salesToUpdate->take(10)->map(function ($sale) {
                    return [
                        $sale->id,
                        $sale->sale_date ? $sale->sale_date->format('Y-m-d') : 'NULL',
                        $sale->created_at->format('Y-m-d'),
                        $sale->created_at->format('Y-m-d')
                    ];
                })->toArray()
            );

            if ($salesToUpdate->count() > 10) {
                $this->info("... and " . ($salesToUpdate->count() - 10) . " more records");
            }

            if ($isDryRun) {
                $this->info('✅ Dry run completed. No changes were made.');
                return 0;
            }

            // Confirm before proceeding
            if (!$this->confirm('Do you want to proceed with updating these sales records?')) {
                $this->info('Operation cancelled.');
                return 0;
            }

            // Start the update process
            $this->info("\n🔄 Starting update process...");
            
            $progressBar = $this->output->createProgressBar($salesToUpdate->count());
            $progressBar->start();

            $updatedCount = 0;
            $errors = [];

            foreach ($salesToUpdate as $sale) {
                try {
                    $oldSaleDate = $sale->sale_date ? $sale->sale_date->format('Y-m-d') : 'NULL';
                    $newSaleDate = $sale->created_at->format('Y-m-d');

                    // Update the sale_date to match created_at date
                    $sale->update([
                        'sale_date' => $sale->created_at->format('Y-m-d')
                    ]);

                    $updatedCount++;
                    
                    // Log the change
                    $this->line("\nUpdated Sale ID {$sale->id}: {$oldSaleDate} → {$newSaleDate}");
                    
                } catch (\Exception $e) {
                    $errors[] = "Sale ID {$sale->id}: " . $e->getMessage();
                    $this->error("\nError updating Sale ID {$sale->id}: " . $e->getMessage());
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            // Summary
            $this->info("✅ Update process completed!");
            $this->info("📊 Summary:");
            $this->info("   - Total sales records: {$totalSales}");
            $this->info("   - Records that needed updating: {$salesToUpdate->count()}");
            $this->info("   - Successfully updated: {$updatedCount}");
            
            if (count($errors) > 0) {
                $this->error("   - Errors: " . count($errors));
                $this->error("   - Error details:");
                foreach ($errors as $error) {
                    $this->error("     • {$error}");
                }
            }

            // Verify the update
            $this->info("\n🔍 Verifying update...");
            $remainingMismatches = Sale::whereRaw('DATE(sale_date) != DATE(created_at)')
                ->orWhereNull('sale_date')
                ->count();

            if ($remainingMismatches === 0) {
                $this->info("✅ Verification successful! All sales now have sale_date equal to created_at date.");
            } else {
                $this->warn("⚠️  Warning: {$remainingMismatches} sales still have mismatched dates.");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ An error occurred: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return 1;
        }
    }
}
