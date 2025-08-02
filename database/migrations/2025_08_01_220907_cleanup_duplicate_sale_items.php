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
        // Clean up duplicate sale items by keeping only the first occurrence
        // and deleting subsequent duplicates
        DB::statement("
            DELETE si1 FROM sale_items si1
            INNER JOIN sale_items si2 
            WHERE si1.id > si2.id 
            AND si1.sale_id = si2.sale_id 
            AND si1.product_id = si2.product_id
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration cannot be reversed as it deletes data
        // No action needed in down method
    }
};
