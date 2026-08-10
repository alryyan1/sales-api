<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add 'card' to the purchase_payments.method enum (it already has
     * 'bank_transfer' from earlier legacy compatibility values).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE purchase_payments MODIFY COLUMN method ENUM('cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund', 'other', 'bankak', 'fawry', 'ocash', 'card') DEFAULT 'cash'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('purchase_payments')
            ->where('method', 'card')
            ->update(['method' => 'other']);

        DB::statement("ALTER TABLE purchase_payments MODIFY COLUMN method ENUM('cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund', 'other', 'bankak', 'fawry', 'ocash') DEFAULT 'cash'");
    }
};
