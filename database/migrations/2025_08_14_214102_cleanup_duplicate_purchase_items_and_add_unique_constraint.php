<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, clean up duplicate purchase items
        // Keep only the first occurrence of each purchase_id/product_id combination
        $duplicates = DB::table('purchase_items')
            ->select('purchase_id', 'product_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('purchase_id', 'product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            // Delete all records except the one with the minimum ID
            DB::table('purchase_items')
                ->where('purchase_id', $duplicate->purchase_id)
                ->where('product_id', $duplicate->product_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        // Now add the unique constraint
        Schema::table('purchase_items', function (Blueprint $table) {
            // Add unique constraint to prevent duplicate product entries in the same purchase
            $table->unique(['purchase_id', 'product_id'], 'purchase_items_purchase_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            // Drop the unique constraint
            $table->dropUnique('purchase_items_purchase_product_unique');
        });
    }
};
