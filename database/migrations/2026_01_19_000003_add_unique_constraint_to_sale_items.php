<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Clean up duplicates before adding the unique constraint
        $duplicates = DB::table('sale_items')
            ->select('sale_id', 'product_id', DB::raw('COUNT(*) as count'))
            ->groupBy('sale_id', 'product_id')
            ->having('count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            // Get all items for this sale and product
            $items = DB::table('sale_items')
                ->where('sale_id', $duplicate->sale_id)
                ->where('product_id', $duplicate->product_id)
                ->orderBy('id')
                ->get();

            if ($items->count() > 1) {
                // Keep the first item
                $firstItem = $items->first();
                $totalQuantity = 0;
                $totalPrice = 0;

                // Calculate totals from all items
                foreach ($items as $item) {
                    $totalQuantity += $item->quantity;
                    $totalPrice += $item->total_price;
                }

                // Update the first item with merged values
                DB::table('sale_items')
                    ->where('id', $firstItem->id)
                    ->update([
                        'quantity' => $totalQuantity,
                        'total_price' => $totalPrice,
                        // unit_price might differ, but we keep the first one's price as a compromise,
                        // or recalculate. Usually unit price should be similar.
                    ]);

                // Delete the other items
                $idsToDelete = $items->pluck('id')->filter(function ($id) use ($firstItem) {
                    return $id !== $firstItem->id;
                });

                DB::table('sale_items')->whereIn('id', $idsToDelete)->delete();
            }
        }

        // 2. Add the unique constraint
        Schema::table('sale_items', function (Blueprint $table) {
            $table->unique(['sale_id', 'product_id'], 'sale_items_sale_id_product_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropUnique('sale_items_sale_id_product_id_unique');
        });
    }
};
