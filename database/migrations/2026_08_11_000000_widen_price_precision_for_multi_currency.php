<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen price/cost columns from 2 to 4 decimal places so currencies with
     * more precision than SDG/USD (e.g. OMR, 3 decimals) aren't truncated at storage time.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('sale_price', 15, 4)->nullable()->change();
            $table->decimal('cost_price', 15, 4)->nullable()->change();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 4)->change();
            $table->decimal('total_cost', 12, 4)->change();
            $table->decimal('sale_price', 10, 4)->nullable()->change();
            $table->decimal('sale_price_stocking_unit', 10, 4)->nullable()->change();
            $table->decimal('cost_per_sellable_unit', 10, 4)->default(0.00)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('sale_price', 15, 2)->nullable()->change();
            $table->decimal('cost_price', 15, 2)->nullable()->change();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->change();
            $table->decimal('total_cost', 12, 2)->change();
            $table->decimal('sale_price', 10, 2)->nullable()->change();
            $table->decimal('sale_price_stocking_unit', 10, 2)->nullable()->change();
            $table->decimal('cost_per_sellable_unit', 10, 2)->default(0.00)->change();
        });
    }
};
