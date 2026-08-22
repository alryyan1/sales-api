<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen sales-side money columns from 2 to 4 decimal places, mirroring
     * 2026_08_11_000000_widen_price_precision_for_multi_currency.php which did
     * the same for products/purchase_items — so OMR (3 decimals) amounts on
     * sales, sale items, payments, and sale returns aren't truncated at storage time.
     */
    public function up(): void
    {
        // Note: total_amount/paid_amount/subtotal are NOT stored columns on `sales` —
        // they're computed live via Sale::getCalculatedTotalAmountAttribute() etc. from
        // sale_items.total_price and payments.amount, both widened below. discount_amount
        // is the only sales-table money column actually persisted.
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('discount_amount', 15, 4)->default(0)->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_price', 15, 4)->change();
            $table->decimal('total_price', 15, 4)->change();
            $table->decimal('cost_price_at_sale', 15, 4)->default(0.00)->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount', 15, 4)->change();
        });

        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->decimal('price', 15, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->default(0)->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_price', 10, 2)->change();
            $table->decimal('total_price', 12, 2)->change();
            $table->decimal('cost_price_at_sale', 10, 2)->default(0.00)->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->change();
        });

        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->change();
        });
    }
};
