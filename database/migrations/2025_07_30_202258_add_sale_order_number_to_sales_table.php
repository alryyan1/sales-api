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
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedInteger('sale_order_number')->nullable()->after('id');
        });

        // Populate existing sales with sale_order_number based on their creation order within each day
        DB::statement("
            UPDATE sales 
            SET sale_order_number = (
                SELECT COUNT(*) + 1
                FROM sales s2 
                WHERE DATE(s2.created_at) = DATE(sales.created_at) 
                AND s2.created_at <= sales.created_at
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('sale_order_number');
        });
    }
};
