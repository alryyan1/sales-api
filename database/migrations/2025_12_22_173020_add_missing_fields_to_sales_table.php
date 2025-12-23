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
            // Restore deleted columns or add new ones as requested
            if (!Schema::hasColumn('sales', 'total_amount')) {
                $table->decimal('total_amount', 12, 2)->default(0.00);
            }
            if (!Schema::hasColumn('sales', 'tax')) {
                $table->decimal('tax', 12, 2)->default(0.00)->after('total_amount');
            }
            // discount/discount_amount might already exist, check before adding or just add 'discount' if user specifically meant a 'discount' column vs 'discount_amount'
            // User requested "discount". Existing column is "discount_amount". I will add "discount" as a separate column or alias?
            // Usually "discount" implies the amount or percentage. Let's assume they want a "discount" column if "discount_amount" isn't enough, but "discount_amount" is likely what they mean.
            // I'll check if 'discount' exists, if not add it, but maybe better to stick to existing 'discount_amount' and 'discount_type' if they serve the purpose.
            // However, to strictly follow "id, customer_id, warehouse_id, total_amount, tax, discount, paid_amount, payment_status"
            if (!Schema::hasColumn('sales', 'discount')) {
                $table->decimal('discount', 12, 2)->default(0.00)->after('tax');
            }

            if (!Schema::hasColumn('sales', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->default(0.00);
            }

            if (!Schema::hasColumn('sales', 'payment_status')) {
                $table->enum('payment_status', ['paid', 'partial', 'unpaid', 'refunded'])->default('unpaid');
            }

            // Re-adding status if missing, as it's standard for sales (completed/cancelled) even if payment_status tracks payment.
            if (!Schema::hasColumn('sales', 'status')) {
                $table->enum('status', ['completed', 'pending', 'cancelled'])->default('completed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['total_amount', 'tax', 'discount', 'paid_amount', 'payment_status', 'status']);
        });
    }
};
