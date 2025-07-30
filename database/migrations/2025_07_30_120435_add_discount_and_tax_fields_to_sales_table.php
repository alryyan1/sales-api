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
            // Add discount fields only (no tax)
            $table->decimal('subtotal', 12, 2)->default(0.00)->after('total_amount');
            $table->decimal('discount_amount', 12, 2)->default(0.00)->after('subtotal');
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable()->after('discount_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'discount_amount', 'discount_type']);
        });
    }
};
