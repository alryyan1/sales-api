<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Stock is now tracked exclusively in product_warehouse table (SSOT).
     */
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_items', 'remaining_quantity')) {
                $table->dropColumn('remaining_quantity');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_items', 'remaining_quantity')) {
                $table->integer('remaining_quantity')->default(0)->after('quantity');
            }
        });
    }
};
