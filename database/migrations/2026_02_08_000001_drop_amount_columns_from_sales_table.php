<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop: discount_amount, total_amount, paid_amount
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $columns = ['discount_amount', 'total_amount', 'paid_amount'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'total_amount')) {
                $table->decimal('total_amount', 12, 2)->default(0.00)->after('sale_order_number');
            }
            if (!Schema::hasColumn('sales', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->default(0.00)->after('total_amount');
            }
            if (!Schema::hasColumn('sales', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0.00)->after('paid_amount');
            }
        });
    }
};
