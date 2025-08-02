<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update the ENUM column to include 'refund'
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund', 'other') DEFAULT 'cash'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'refund' from the ENUM
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'other') DEFAULT 'cash'");
    }
};
