<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * purchase_items money columns were widened to decimal(10,4)/(12,4) in
     * 2026_08_11_000000_widen_price_precision_for_multi_currency.php, but that
     * only allows 6 integer digits (max 999,999.9999) — large costs/prices
     * (e.g. 20,000,000) overflow and fail at save time. Match the (15,4)
     * widening already applied to products/sale_items.
     */
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->change();
            $table->decimal('total_cost', 17, 4)->change();
            $table->decimal('sale_price', 15, 4)->nullable()->change();
            $table->decimal('sale_price_stocking_unit', 15, 4)->nullable()->change();
            $table->decimal('cost_per_sellable_unit', 15, 4)->default(0.00)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 4)->change();
            $table->decimal('total_cost', 12, 4)->change();
            $table->decimal('sale_price', 10, 4)->nullable()->change();
            $table->decimal('sale_price_stocking_unit', 10, 4)->nullable()->change();
            $table->decimal('cost_per_sellable_unit', 10, 4)->default(0.00)->change();
        });
    }
};
