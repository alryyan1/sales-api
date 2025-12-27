<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset all inventory quantities to zero';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (! $this->confirm('Are you sure you want to reset ALL inventory to ZERO? This cannot be undone.')) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        $this->info('Resetting inventory...');

        try {
            DB::transaction(function () {
                // 1. Reset Products Global Stock
                $updatedProducts = DB::table('products')->update(['stock_quantity' => 0]);
                $this->info("Types of products reset: {$updatedProducts}");

                // 2. Reset Warehouse Specific Stock
                $updatedWarehouses = DB::table('product_warehouse')->update(['quantity' => 0]);
                $this->info("Warehouse records reset: {$updatedWarehouses}");

                // 3. Reset Purchase Items Remaining Quantity (Effectively marking them as consumed)
                $updatedItems = DB::table('purchase_items')->update(['remaining_quantity' => 0]);
                $this->info("Purchase batches reset: {$updatedItems}");
            });

            $this->info('Inventory reset successfully. All stocks are now 0.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("An error occurred: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
