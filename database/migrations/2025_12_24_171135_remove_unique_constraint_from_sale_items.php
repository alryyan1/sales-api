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
            // 1. Drop the unique index by its name
            // Often just dropping the index is enough. Dropping FKs is only needed if the index
            // is strictly bound to the FK constraint logic in MySQL (which sometimes happens).
            // Let's try dropping just the index first. If foreign keys rely on it, we might need to drop them. 
            // However, usually FKs use *an* index, not necessarily *this unique* one if another exists.
            // But to be safe and thorough:

            $table->dropForeign(['sale_id']);
            $table->dropForeign(['product_id']);

            $table->dropUnique('unique_sale_product');

            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->unique(['sale_id', 'product_id'], 'unique_sale_product');
        });
    }
};
