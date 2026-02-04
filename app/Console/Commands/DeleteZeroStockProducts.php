<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Services\ProductDeletionService;
use Illuminate\Support\Facades\DB;

class DeleteZeroStockProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:delete-zero-stock';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all products that have zero stock in all warehouses, along with their history.';

    protected $deletionService;

    public function __construct(ProductDeletionService $deletionService)
    {
        parent::__construct();
        $this->deletionService = $deletionService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Scanning for products with zero stock...');

        // Find products where total stock across all warehouses is <= 0
        // We use check against product_warehouse pivot sum.
        // OR products that have NO warehouse record at all.
        $productsToDelete = Product::whereDoesntHave('warehouses', function ($query) {
            $query->where('quantity', '>', 0);
        })->get();

        $count = $productsToDelete->count();

        if ($count === 0) {
            $this->info('No products with zero stock found.');
            return;
        }

        $this->warn("Found {$count} products with zero stock.");

        $this->table(
            ['ID', 'Name', 'SKU'],
            $productsToDelete->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                ];
            })
        );

        $this->alert("WARNING: This will permanently delete these {$count} products AND ALL their associated sales, purchases, and history.");
        $this->warn("Financial records (Sales/Purchases) will be adjusted but deleted items will be gone forever.");

        if ($this->confirm('Are you sure you want to proceed?', false)) {
            $bar = $this->output->createProgressBar($count);
            $bar->start();

            foreach ($productsToDelete as $product) {
                try {
                    $this->deletionService->forceDeleteProduct($product);
                } catch (\Exception $e) {
                    $this->error("Failed to delete product ID {$product->id}: " . $e->getMessage());
                    // Continue to next product? Yes.
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info('Operation completed.');
        } else {
            $this->info('Operation cancelled.');
        }
    }
}
