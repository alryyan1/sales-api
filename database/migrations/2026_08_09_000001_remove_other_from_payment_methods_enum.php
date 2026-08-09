<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `payments` MODIFY `method` ENUM('cash', 'bank_transfer', 'visa') DEFAULT 'cash'");
        DB::statement("ALTER TABLE `purchase_payments` MODIFY `method` ENUM('cash', 'bank_transfer', 'visa') DEFAULT 'cash'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `payments` MODIFY `method` ENUM('cash', 'bank_transfer', 'visa', 'other') DEFAULT 'cash'");
        DB::statement("ALTER TABLE `purchase_payments` MODIFY `method` ENUM('cash', 'bank_transfer', 'visa', 'other') DEFAULT 'cash'");
    }
};
