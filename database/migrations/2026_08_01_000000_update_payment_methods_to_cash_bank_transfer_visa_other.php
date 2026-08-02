<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Change payment method options to: cash, bank_transfer, visa, other.
     * Legacy values are remapped: bankak/bank -> bank_transfer, mastercard/mada -> visa,
     * fawry/ocash/refund -> other. Anything else falls back to 'other'.
     */
    public function up(): void
    {
        $map = [
            'bankak' => 'bank_transfer',
            'bank' => 'bank_transfer',
            'mastercard' => 'visa',
            'mada' => 'visa',
            'fawry' => 'other',
            'ocash' => 'other',
            'refund' => 'other',
            'check' => 'other',
            'credit_card' => 'other',
            'store_credit' => 'other',
        ];

        foreach ($map as $old => $new) {
            DB::table('payments')->where('method', $old)->update(['method' => $new]);
            DB::table('purchase_payments')->where('method', $old)->update(['method' => $new]);
        }

        $allowed = ['cash', 'bank_transfer', 'visa', 'other'];
        DB::table('payments')->whereNotIn('method', $allowed)->update(['method' => 'other']);
        DB::table('purchase_payments')->whereNotIn('method', $allowed)->update(['method' => 'other']);

        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'bank_transfer', 'visa', 'other') DEFAULT 'cash'");
        DB::statement("ALTER TABLE purchase_payments MODIFY COLUMN method ENUM('cash', 'bank_transfer', 'visa', 'other') DEFAULT 'cash'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'bankak', 'fawry', 'ocash') DEFAULT 'cash'");
        DB::statement("ALTER TABLE purchase_payments MODIFY COLUMN method ENUM('cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund', 'other', 'bankak', 'fawry', 'ocash') DEFAULT 'cash'");
    }
};
