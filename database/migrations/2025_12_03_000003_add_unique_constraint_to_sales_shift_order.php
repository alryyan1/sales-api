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
        Schema::table('sales', function (Blueprint $table) {
            // Add unique composite index: sale_order_number must be unique per shift
            // This ensures each shift has its own sequence (1, 2, 3, ...)
            $table->unique(['shift_id', 'sale_order_number'], 'sales_shift_order_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_shift_order_unique');
        });
    }
};

