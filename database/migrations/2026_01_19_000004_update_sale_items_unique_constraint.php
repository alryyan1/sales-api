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
        // Disable foreign key checks to allow dropping the index used by the FK
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        Schema::table('sale_items', function (Blueprint $table) {
            // Drop the strict constraint created in the previous migration
            $table->dropUnique('sale_items_sale_id_product_id_unique');

            // Add the new composite constraint that includes batch (purchase_item_id)
            $table->unique(['sale_id', 'product_id', 'purchase_item_id'], 'sale_items_composite_unique');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropUnique('sale_items_composite_unique');
            $table->unique(['sale_id', 'product_id'], 'sale_items_sale_id_product_id_unique');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
};
