<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop: invoice_number, discount_type, notes, tax, discount, payment_status, status
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $columns = ['invoice_number', 'discount_type', 'notes', 'tax', 'discount', 'payment_status', 'status'];
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
            if (!Schema::hasColumn('sales', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->unique()->after('sale_date');
            }
            if (!Schema::hasColumn('sales', 'discount_type')) {
                $table->enum('discount_type', ['percentage', 'fixed'])->nullable()->after('discount_amount');
            }
            if (!Schema::hasColumn('sales', 'notes')) {
                $table->text('notes')->nullable()->after('sale_order_number');
            }
            if (!Schema::hasColumn('sales', 'tax')) {
                $table->decimal('tax', 12, 2)->default(0.00)->after('total_amount');
            }
            if (!Schema::hasColumn('sales', 'discount')) {
                $table->decimal('discount', 12, 2)->default(0.00)->after('tax');
            }
            if (!Schema::hasColumn('sales', 'payment_status')) {
                $table->enum('payment_status', ['paid', 'partial', 'unpaid', 'refunded'])->default('unpaid')->after('paid_amount');
            }
            if (!Schema::hasColumn('sales', 'status')) {
                $table->enum('status', ['completed', 'pending', 'cancelled'])->default('completed')->after('payment_status');
            }
        });
    }
};
