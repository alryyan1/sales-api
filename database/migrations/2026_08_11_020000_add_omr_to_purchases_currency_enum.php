<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add 'OMR' to the purchases.currency enum.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('purchases', 'currency')) {
            DB::statement("ALTER TABLE purchases ADD COLUMN currency ENUM('SDG', 'USD', 'OMR') DEFAULT 'SDG' AFTER notes");
            return;
        }

        DB::statement("ALTER TABLE purchases MODIFY COLUMN currency ENUM('SDG', 'USD', 'OMR') DEFAULT 'SDG'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('purchases', 'currency')) {
            return;
        }

        DB::table('purchases')
            ->where('currency', 'OMR')
            ->update(['currency' => 'SDG']);

        DB::statement("ALTER TABLE purchases MODIFY COLUMN currency ENUM('SDG', 'USD') DEFAULT 'SDG'");
    }
};
