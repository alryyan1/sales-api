<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add 'bank_transfer' and 'card' to the payments.method enum, alongside
     * the existing cash/bankak/fawry/ocash values.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'bankak', 'fawry', 'ocash', 'bank_transfer', 'card') DEFAULT 'cash'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('payments')
            ->whereNotIn('method', ['cash', 'bankak', 'fawry', 'ocash'])
            ->update(['method' => 'cash']);

        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'bankak', 'fawry', 'ocash') DEFAULT 'cash'");
    }
};
