<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('purchase_items', function (Blueprint $table) {
            // 'unit_cost' already exists and serves as the cost_price for this batch.
            // Add sale_price for this batch (optional, can be set at point of sale too)
            $table->decimal('sale_price', 10, 2)->nullable()->after('total_cost');
            $table->date('expiry_date')->nullable()->after('sale_price');
        });
    }

    public function down(): void {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_items', 'sale_price')) {
                $table->dropColumn('sale_price');
            }
            if (Schema::hasColumn('purchase_items', 'expiry_date')) {
                $table->dropColumn('expiry_date');
            }
        });
    }
};