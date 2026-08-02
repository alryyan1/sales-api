<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transit_orders', function (Blueprint $table) {
            $table->unsignedInteger('order_number')->nullable()->unique()->after('id');
            $table->date('order_date')->nullable()->after('order_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transit_orders', function (Blueprint $table) {
            $table->dropUnique(['order_number']);
            $table->dropColumn(['order_number', 'order_date']);
        });
    }
};
