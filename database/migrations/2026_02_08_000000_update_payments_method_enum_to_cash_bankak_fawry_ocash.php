<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Change payments.method enum to only: cash, bankak, fawry, ocash.
     * Existing values not in the new enum are mapped to 'cash'.
     */
    public function up(): void
    {
        $allowed = ['cash', 'bankak', 'fawry', 'ocash'];
        DB::table('payments')
            ->whereNotIn('method', $allowed)
            ->update(['method' => 'cash']);

        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'bankak', 'fawry', 'ocash') DEFAULT 'cash'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund', 'other') DEFAULT 'cash'");
    }
};
