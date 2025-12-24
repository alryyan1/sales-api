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
        Schema::table('purchases', function (Blueprint $table) {
            // Adding indexes for commonly filtered/sorted columns
            $table->index('status');
            $table->index('purchase_date');
            $table->index('created_at');
            $table->index('supplier_id');
            $table->index('user_id');
            $table->index('reference_number');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            // Adding indexes for subquery optimization
            $table->index('expiry_date');
            $table->index('remaining_quantity');
            $table->index('batch_number');
            // product_id and purchase_id likely have indexes via foreignId, but if not:
            $table->index('product_id');
            $table->index('purchase_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['purchase_date']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['supplier_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['reference_number']);
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropIndex(['expiry_date']);
            $table->dropIndex(['remaining_quantity']);
            $table->dropIndex(['batch_number']);
            $table->dropIndex(['product_id']);
            $table->dropIndex(['purchase_id']);
        });
    }
};
