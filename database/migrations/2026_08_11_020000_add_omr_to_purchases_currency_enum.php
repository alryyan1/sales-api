<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add 'OMR' to the purchases.currency enum.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE purchases MODIFY COLUMN currency ENUM('SDG', 'USD', 'OMR') DEFAULT 'SDG'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('purchases')
            ->where('currency', 'OMR')
            ->update(['currency' => 'SDG']);

        DB::statement("ALTER TABLE purchases MODIFY COLUMN currency ENUM('SDG', 'USD') DEFAULT 'SDG'");
    }
};
