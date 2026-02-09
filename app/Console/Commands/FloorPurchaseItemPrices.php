<?php

namespace App\Console\Commands;

use App\Models\PurchaseItem;
use Illuminate\Console\Command;

class FloorPurchaseItemPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase-items:floor-prices
                            {--step=100 : Floor to nearest (e.g. 100 => 3024→3000, 11263→11200)}
                            {--dry-run : Show what would be updated without saving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Floor purchase item sale_price and sale_price_stocking_unit to the nearest step (e.g. 100).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $step = (int) $this->option('step');
        $dryRun = $this->option('dry-run');

        if ($step <= 0) {
            $this->error('Option --step must be a positive integer.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run: no changes will be saved.');
        }

        $query = PurchaseItem::query()
            ->where(function ($q) {
                $q->whereNotNull('sale_price')
                    ->orWhereNotNull('sale_price_stocking_unit');
            });

        $total = $query->count();
        if ($total === 0) {
            $this->info('No purchase items with sale_price or sale_price_stocking_unit found.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updatedCount = 0;
        $changedCount = 0;

        $query->chunkById(200, function ($items) use ($step, $dryRun, &$updatedCount, &$changedCount, $bar) {
            foreach ($items as $item) {
                $bar->advance();

                $updates = [];
                $salePrice = $item->sale_price !== null ? (float) $item->sale_price : null;
                $salePriceStocking = $item->sale_price_stocking_unit !== null ? (float) $item->sale_price_stocking_unit : null;

                if ($salePrice !== null) {
                    $floored = $this->floorToStep($salePrice, $step);
                    if ($floored !== $salePrice) {
                        $updates['sale_price'] = (string) $floored;
                    }
                }
                if ($salePriceStocking !== null) {
                    $floored = $this->floorToStep($salePriceStocking, $step);
                    if ($floored !== $salePriceStocking) {
                        $updates['sale_price_stocking_unit'] = (string) $floored;
                    }
                }

                if (count($updates) > 0) {
                    $changedCount++;
                    if (!$dryRun) {
                        $item->update($updates);
                        $updatedCount++;
                    }
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Dry run complete. {$changedCount} purchase item(s) would have been updated.");
        } else {
            $this->info("Updated {$updatedCount} purchase item(s) (floored to nearest {$step}).");
        }

        return self::SUCCESS;
    }

    /**
     * Floor a value to the nearest step (e.g. step=100: 3024→3000, 11263→11200).
     */
    private function floorToStep(float $value, int $step): float
    {
        return floor($value / $step) * $step;
    }
}
