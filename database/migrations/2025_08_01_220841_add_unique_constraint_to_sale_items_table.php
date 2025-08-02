<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // Add unique constraint to prevent duplicate sale items
            // This ensures that the same product cannot be added multiple times to the same sale
            $table->unique(['sale_id', 'product_id'], 'unique_sale_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // Remove the unique constraint
            $table->dropUnique('unique_sale_product');
        });
    }
};
